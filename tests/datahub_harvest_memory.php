<?php

declare(strict_types=1);

// Standalone regression check. No application bootstrap, configuration or network.
// Run with PHP's mbstring and pdo_sqlite extensions and memory_limit=128M.
namespace App\Datahub {
    const CURLOPT_RETURNTRANSFER = 1;
    const CURLOPT_FOLLOWLOCATION = 2;
    const CURLOPT_MAXREDIRS = 3;
    const CURLOPT_CONNECTTIMEOUT = 4;
    const CURLOPT_TIMEOUT = 5;
    const CURLOPT_USERAGENT = 6;
    const CURLINFO_RESPONSE_CODE = 7;

    final class HarvestHttpFixture
    {
        public static \Closure $respond;
    }

    function curl_init(string $url): object
    {
        if (!str_starts_with($url, 'https://datahub.invalid/')) {
            throw new \RuntimeException('Only the fake Datahub is allowed.');
        }

        return (object) ['url' => $url];
    }

    function curl_setopt_array(object $handle, array $options): bool { return true; }
    function curl_exec(object $handle): string { return (HarvestHttpFixture::$respond)($handle->url); }
    function curl_error(object $handle): string { return ''; }
    function curl_getinfo(object $handle, int $option): int { return 200; }
    function curl_close(object $handle): void {}
}

namespace {
    use App\Command\HarvestDatahubObjectsCommand;
    use App\Datahub\CurlHttpAdapter;
    use App\Datahub\HarvestHttpFixture;
    use App\Entity\IIIFManifest;
    use App\Entity\ObjectManifest;
    use App\Entity\ObjectRecord;
    use Doctrine\DBAL\DriverManager;
    use Doctrine\ORM\EntityManager;
    use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
    use Doctrine\ORM\ORMSetup;
    use Doctrine\ORM\Tools\SchemaTool;
    use Symfony\Component\Cache\Adapter\ArrayAdapter;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\BufferedOutput;
    use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

    $root = dirname(__DIR__);
    require $root . '/vendor/composer/ClassLoader.php';
    require $root . '/vendor/symfony/deprecation-contracts/function.php';
    $loader = new Composer\Autoload\ClassLoader();
    foreach (require $root . '/vendor/composer/autoload_psr4.php' as $namespace => $paths) {
        $loader->addPsr4($namespace, $paths);
    }
    $loader->register();

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function database(string $root): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new EntityManager($connection, $config);
        (new SchemaTool($manager))->createSchema(array_map(
            $manager->getClassMetadata(...),
            [ObjectRecord::class, IIIFManifest::class, ObjectManifest::class],
        ));

        return $manager;
    }

    function harvest(EntityManager $manager, int $total, int $padding = 0, ?int $limit = null, bool $includeTotal = true, bool $filterSets = false): string
    {
        $nextOffset = 0;
        HarvestHttpFixture::$respond = static function (string $url) use ($total, $padding, $includeTotal, $filterSets, &$nextOffset): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $offset = (int) $query['offset'];
            $pageSize = (int) $query['limit'];
            check($offset === $nextOffset, 'Pagination skipped or repeated records.');
            check($pageSize <= 25, 'REST pages must stay small.');
            $records = [];
            for ($id = $offset + 1; $id <= min($total, $offset + $pageSize); ++$id) {
                $records[] = [
                    'id' => $id,
                    'record_ids' => ['fixture-' . $id],
                    'sets' => [$filterSets && $id % 2 === 0 ? 'fixture:excluded' : 'fixture:included'],
                    'unused' => str_repeat('x', $padding),
                    'json' => [
                        ['name' => '{http://www.lido-schema.org}workID', 'value' => 'INV-' . $id],
                        ['name' => '{http://www.lido-schema.org}title', 'value' => 'Fixture ' . $id],
                    ],
                ];
            }
            $nextOffset += count($records);
            $payload = ['_embedded' => ['records' => $records]];
            if ($includeTotal) {
                $payload['total'] = $total;
            }

            return json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        };

        $parameters = new ParameterBag(['datahub' => [
            'url' => 'https://datahub.invalid',
            'metadata_prefix' => 'lido',
            'namespace' => 'lido',
            'language' => 'nl',
            'sets' => ['fixture:included'],
            'placeholder_images' => [],
            'data_definition' => ['id' => ['xpath' => 'workID'], 'title_nl' => ['xpath' => 'title']],
        ]]);
        $command = new HarvestDatahubObjectsCommand($manager, $parameters);
        $input = new ArrayInput($limit === null ? [] : ['--limit' => $limit]);
        $output = new BufferedOutput();
        $status = $command->run($input, $output);
        $log = $output->fetch();
        check($status === 0, 'Harvest failed: ' . $log);

        return $log;
    }

    // Larger than the production run that failed, without any external services.
    $manager = database($root);
    $log = harvest($manager, 6701);
    check(str_contains($log, 'Nieuw: 6701, bijgewerkt: 0, overgeslagen: 0.'), 'Incorrect full-harvest counts.');
    check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM object_records') === 6701, 'Full harvest incomplete.');
    $object = $manager->getRepository(ObjectRecord::class)->findOneBy(['inventoryNumber' => 'INV-1']);
    $object->setTitle('Local title');
    $manager->flush();
    $manager->clear();
    $log = harvest($manager, 6701, limit: 27);
    check(str_contains($log, 'Nieuw: 0, bijgewerkt: 27, overgeslagen: 0.'), 'Incorrect rerun counts.');
    check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM object_records') === 6701, 'Rerun created duplicates.');
    check($manager->getRepository(ObjectRecord::class)->findOneBy(['inventoryNumber' => 'INV-1'])->getTitle() === 'Local title', 'Local changes overwritten.');
    $manager->getConnection()->close();
    unset($manager, $object);

    // Exercise large response bodies and the final partial page without a total.
    $manager = database($root);
    harvest($manager, 53, padding: 512 * 1024, includeTotal: false);
    check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM object_records') === 53, 'Large-page harvest incomplete.');
    $manager->getConnection()->close();
    unset($manager);

    $manager = database($root);
    harvest($manager, 80, limit: 27, filterSets: true);
    check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM object_records') === 27, 'Limit or set filtering incorrect.');
    $manager->getConnection()->close();

    $adapter = new CurlHttpAdapter();
    foreach (['', " \t\n\r\0\x0B"] as $blank) {
        HarvestHttpFixture::$respond = static fn (): string => $blank;
        try {
            $adapter->request('https://datahub.invalid/');
            throw new RuntimeException('Blank response accepted.');
        } catch (Phpoaipmh\Exception\HttpException $exception) {
            check(str_contains($exception->getMessage(), 'empty'), 'Unexpected response error.');
        }
    }
    HarvestHttpFixture::$respond = static fn (): string => " 0\n";
    check($adapter->request('https://datahub.invalid/') === " 0\n", 'Response content altered.');

    printf("PASS: 6,701 records, rerun, local changes, large pages, filtering, limits and response validation. Peak PHP memory: %.1f MiB.\n", memory_get_peak_usage(true) / 1024 / 1024);
}
