<?php
declare(strict_types=1);

// Synthetic API pages and a disposable workspace SQLite file. No kernel, .env or network.
use App\Entity\{ObjectRecord, Project};
use App\Service\{ObjectChangeLogger, TmsActorSync, TmsApiClient, TmsProjectSync, TmsReportSync};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

ini_set('memory_limit', '128M');
$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->register();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$counts = ['exhibitions' => 10, 'loans' => 20, 'objects' => 1000, 'object_titles' => 10000,
    'exhibition_objects' => 1000, 'loan_objects' => 1000, 'exhibition_loans' => 20,
    'shipments' => 20, 'parties' => 10000, 'documents' => 95000];
$payload = str_repeat("Fictional metadata — 日本語\n", 80);
$requests = 0;
$transport = static function (string $url, array $headers) use ($counts, $payload, &$requests): array {
    ++$requests;
    $manifest = ['schema_version' => 2, 'scope' => ['exhibition_ids' => null],
        'datasets' => array_fill_keys(array_keys($counts), ['enabled' => true])];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    if ($query === []) return ['status' => 200, 'body' => json_encode($manifest, JSON_THROW_ON_ERROR)];
    $dataset = $query['dataset'];
    $offset = (int) $query['offset'];
    $limit = (int) $query['limit'];
    $data = [];
    for ($id = $offset + 1; $id <= min($offset + $limit, $counts[$dataset]); ++$id) {
        $object = (string) (1 + ($id - 1) % 1000);
        $loan = (string) (1001 + ($id - 1) % 20);
        $exhibition = (string) (2001 + ($id - 1) % 10);
        $person = (string) (1 + ($id - 1) % 10);
        $row = match ($dataset) {
            'exhibitions' => ['ExhibitionID' => $exhibition, 'ProjectNumber' => 'EX-' . $id,
                'ExhTitle' => 'Fixture exhibition', 'DisplayDate' => null],
            'loans' => ['LoanID' => $loan, 'LoanNumber' => 'LN-' . $id, 'LoanIn' => '1',
                'Description' => 'Fixture loan', 'BeginISODate' => null, 'EndISODate' => null, 'LoanStatus' => 'Open'],
            'objects' => ['ObjectID' => $object, 'ObjectNumber' => 'MEMORY.' . $id, 'ObjectName' => 'Fixture object', 'Dated' => null],
            'object_titles' => ['TitleID' => (string) ($id - 1), 'ObjectID' => $object, 'Title' => 'Title ' . $id,
                'LanguageID' => '2', 'Displayed' => '1', 'Active' => '1'],
            'exhibition_objects' => ['ExhObjXrefID' => (string) $id, 'ObjectID' => $object, 'ExhibitionID' => $exhibition],
            'loan_objects' => ['LoanObjXrefID' => (string) $id, 'ObjectID' => $object, 'LoanID' => $loan, 'Remarks' => $payload],
            'exhibition_loans' => ['ExhibitionID' => $exhibition, 'LoanID' => $loan],
            'shipments' => ['ShipmentID' => (string) $id, 'LoanID' => $loan, 'Remarks' => $payload],
            'documents' => ['MediaXrefID' => (string) $id, 'TableID' => '108', 'ID' => $object,
                'PrimaryFileName' => 'fixture-' . $id . '.pdf', 'Remarks' => $payload],
            'parties' => ['ConXrefDetailID' => (string) $id, 'TableID' => $id > 9900 ? '81' : '108',
                'ID' => $id > 9900 ? $loan : $object, 'ConstituentID' => $person, 'NameID' => $person,
                'AddressID' => $person, 'UnMasked' => '1', 'DisplayName' => 'Fixture person ' . $person,
                'Role' => $id > 9900 ? 'Bruikleengever' : 'Maker', 'RoleTypeID' => $id > 9900 ? '17' : '1',
                'Active' => '1', 'Displayed' => '1', 'AddressActive' => '1', 'StreetLine1' => 'Fixture street',
                'StreetLine2' => null, 'StreetLine3' => null, 'ZipCode' => '0001', 'City' => 'Fixture city',
                'State' => null, 'Country' => 'Fixture country', 'Remarks' => $payload],
        };
        $data[] = $row;
    }
    return ['status' => 200, 'body' => json_encode($manifest + [
        'dataset' => $dataset, 'offset' => $offset, 'limit' => $limit, 'count' => count($data),
        'next_offset' => $offset + $limit < $counts[$dataset] ? $offset + $limit : null, 'data' => $data,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
};

$directory = $root . '/tmp/tms-memory-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$em = null;
try {
    $orm = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, null, new ArrayAdapter());
    $orm->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $orm->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
    $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $directory . '/fixture.sqlite']), $orm);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $client = new TmsApiClient(true, 'https://fixtures.invalid/loan_projects.php', 'fixture', 'FAKE-password', $transport);
    $sync = new TmsProjectSync($client, $em, new ObjectChangeLogger($em), $directory, new TmsActorSync($em),
        new TmsReportSync($em, '8369', 'Koninklijk Museum voor Schone Kunsten Antwerpen'));
    $start = microtime(true);
    $result = $sync->synchronize();
    check($result['objects'] === 1000 && $result['projects'] === 30 && $result['links'] === 2000, 'Incomplete large import.');
    check($result['actors'] === 10 && $result['actor_links'] > 10000, 'Actor assignments missing from large import.');
    foreach (['1', '1000'] as $id) {
        $object = $em->getRepository(ObjectRecord::class)->findOneBy(['tmsId' => $id]);
        $metadata = $object->getTmsData();
        check(count($metadata['documents']) === 95 && count($metadata['titles']) === 10, 'Grouped metadata was lost.');
        check($metadata['documents'][94]['Remarks'] === $payload, 'File positions lost Unicode or multiline metadata.');
        check(count($metadata['parties']) >= 9, 'Object actor metadata missing.');
        $em->clear();
    }
    $loan = $em->getRepository(Project::class)->findOneBy(['tmsType' => 'loans', 'tmsId' => '1001']);
    check(count($loan->getTmsData()['parties']) === 5 && count($loan->getTmsData()['shipments']) === 1, 'Project metadata was lost.');
    check(glob($directory . '/download-*') === [], 'Downloads were not removed after import.');
    printf("OK: 95,000 documents, 10,000 actor relations, 10,000 titles; peak %.1f MiB under 128M; %d fake requests; %.1f s.\n",
        memory_get_peak_usage(true) / 1048576, $requests, microtime(true) - $start);
} finally {
    $em?->getConnection()->close();
    foreach (glob($directory . '/download-*') ?: [] as $file) unlink($file);
    foreach (['run.lock', 'fixture.sqlite', 'fixture.sqlite-journal'] as $name) {
        if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
    }
    rmdir($directory);
}
