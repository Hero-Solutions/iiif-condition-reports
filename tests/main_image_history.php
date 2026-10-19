<?php
declare(strict_types=1);

// SQLite and URL strings only. No application bootstrap, environment files or HTTP.
use App\Command\HarvestDatahubObjectsCommand;
use App\Controller\{ReportAnnotationController, ReportController};
use App\Datahub\LidoMeasurements;
use App\Entity\{Annotation, DamageCase, IIIFManifest, ObjectImageVersion, ObjectManifest, ObjectRecord, Report, ReportMainImage, ReportSeries};
use App\Service\{MainImageHistory, ObjectChangeLogger, ObjectImageStorage, ReportAuthorProvider};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\{Container, ParameterBag\ParameterBag};
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};
use Symfony\Component\Translation\Translator;

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->register();

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function invoke(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

// Rendering a PDF may embed a bounded preview; saving/history have no storage dependency.
final class PreviewFixtures extends ObjectImageStorage
{
    public function dataUri(?string $url): ?string { return null; }
    public function externalDataUri(string $url): string
    {
        check(str_starts_with($url, 'https://images.invalid/') && str_contains($url, '/full/2000,/'), 'PDF uses a bounded IIIF preview.');
        return 'data:image/jpeg;base64,' . base64_encode($url);
    }
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$container = new Container();
$container->set('security.authorization_checker', new class implements AuthorizationCheckerInterface {
    public function isGranted(mixed $attribute, mixed $subject = null): bool { return false; }
});
$container->set('security.token_storage', new TokenStorage());
$container->set('security.csrf.token_manager', new class implements CsrfTokenManagerInterface {
    public function getToken(string $id): CsrfToken { return new CsrfToken($id, 'fixture'); }
    public function refreshToken(string $id): CsrfToken { return $this->getToken($id); }
    public function removeToken(string $id): ?string { return null; }
    public function isTokenValid(CsrfToken $token): bool { return $token->getValue() === 'fixture'; }
});

function fixture(): array
{
    global $config, $container;
    $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $object = (new ObjectRecord('IMAGE-TEST', 'Fixture'))->applyDatahubSync('IMAGE-TEST', 'fixture', [
        'iiif_manifest_url' => 'https://images.invalid/a/manifest',
        'iiif_image_info_url' => 'https://images.invalid/a/front/info.json',
    ]);
    $manifest = (new IIIFManifest())->setManifestId('https://images.invalid/a/manifest')->setSourceUrl('https://images.invalid/a/manifest');
    $link = new ObjectManifest($object, $manifest, ObjectManifest::ROLE_SOURCE);
    $series = new ReportSeries($object);
    $report = new Report($series);
    $damage = new DamageCase($report, 'fixture-case', 'Fixture damage', '#ff0000');
    foreach ([$object, $manifest, $link, $series, $report, $damage] as $entity) $manager->persist($entity);
    $manager->flush();
    $history = new MainImageHistory($manager);
    $controller = new ReportAnnotationController($manager, new ReportAuthorProvider($manager), $history);
    $controller->setContainer($container);
    return [$manager, $history, $controller, $report, $damage];
}
function payload(MainImageHistory $history, Report $report, DamageCase $damage): array
{
    return [
        'damageCaseId' => $damage->getId(), 'sourceKey' => 'main:2', 'reportImageId' => null,
        'mainImageFingerprint' => $history->forReport($report)['fingerprint'],
        'mainImageSources' => [
            ['key' => 'main:1', 'url' => 'https://images.invalid/a/front/info.json'],
            ['key' => 'main:2', 'url' => 'https://images.invalid/a/back/info.json'],
        ],
        'annotation' => ['id' => 'fixture-polygon', 'bodies' => [], 'target' => [
            'annotation' => 'fixture-polygon',
            'selector' => ['type' => 'POLYGON', 'geometry' => [
                'points' => [[10.5, 20.5], [30.5, 20.5], [30.5, 40.5], [10.5, 20.5]],
                'bounds' => ['minX' => 10.5, 'minY' => 20.5, 'maxX' => 30.5, 'maxY' => 40.5],
            ]],
            'sourceDimensions' => ['width' => 6979, 'height' => 4949],
        ]],
    ];
}
function request(array $payload, string $csrf = 'fixture'): Request
{
    return Request::create('/fixture', 'POST', server: ['HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($payload));
}

[$manager, $history, $controller, $report, $damage] = fixture();
$payload = payload($history, $report, $damage);
$response = $controller->saveAnnotation($report, request($payload));
check($response->getStatusCode() === 201, 'A polygon saves with URL references only.');
check(json_decode($response->getContent(), true)['annotation']['target'] === $payload['annotation']['target'], 'Keep geometry and original dimensions.');
$version = $manager->getRepository(ReportMainImage::class)->findOneBy(['report' => $report])->getImageVersion();
check(array_column($version->getImages(), 'info_url') === array_column($payload['mainImageSources'], 'url'), 'Store exact per-canvas IIIF URLs.');
check($version->getSource()['manifestUrl'] === 'https://images.invalid/a/manifest', 'Keep the original manifest URL in the database.');
check($controller->saveAnnotation($report, request($payload))->getStatusCode() === 200, 'Repeated saves update the existing annotation.');
check($manager->getRepository(ObjectImageVersion::class)->count([]) === 1, 'Repeated saves reuse URL history.');

$empty = new Report($report->getSeries());
$older = new Report($report->getSeries());
$undone = new Report($report->getSeries());
foreach ([$empty, $older, $undone] as $entity) $manager->persist($entity);
foreach ([$older, $undone] as $other) {
    $case = new DamageCase($other, 'case-' . spl_object_id($other), 'Damage', '#000000');
    $line = (new Annotation($other, $case, 'main:2', 'old-line'))->setTarget($payload['annotation']['target']);
    if ($other === $undone) $line->delete();
    $manager->persist($case);
    $manager->persist($line);
}
$manager->flush();
$history->preserveForAnnotation($undone, $payload['mainImageFingerprint'], 'main:2', $payload['mainImageSources']);
$manager->flush();
$report->finalize(1);

// The real harvest update path must preserve URLs without downloading old images.
$command = new HarvestDatahubObjectsCommand($manager, new ParameterBag(), new ObjectChangeLogger($manager), new LidoMeasurements(), $history);
$prepared = ['inventory_number' => 'IMAGE-TEST', 'record_identifier' => 'fixture', 'source_data' => [
    'iiif_manifest_url' => 'https://images.invalid/b/manifest',
    'iiif_image_info_url' => 'https://images.invalid/b/info.json',
], 'synced_at' => new DateTimeImmutable('2026-10-19 12:00')];
$manager->wrapInTransaction(function () use ($command, $prepared): void {
    invoke($command, 'preloadBatch', [$prepared]);
    invoke($command, 'syncPreparedRecord', $prepared);
});
check($history->forReport($report)['images'] === $version->getImages(), 'Final reports retain their old IIIF URLs.');
check($history->forReport($older)['images'] === $version->getImages(), 'Existing drawings share the original URL history.');
check($history->forReport($empty)['manifestUrl'] === 'https://images.invalid/b/manifest', 'An empty report follows the current source.');
check($history->forReport($undone)['manifestUrl'] === 'https://images.invalid/b/manifest', 'Deleted drawings do not pin old URLs.');
check(count($history->previousImages($report->getObjectRecord())) === 1, 'History records the changed source once.');
$thumbs = $history->thumbnailsForReports([$report, $empty], [$report->getObjectRecord()->getId() => '/current.jpg']);
check($thumbs[$report->getId()] === $version->getImages()[0]['thumbnail_url'], 'Report lists use the old thumbnail URL.');

$reflection = new ReflectionClass(ReportController::class);
$output = $reflection->newInstanceWithoutConstructor();
foreach (['entityManager' => $manager, 'mainImages' => $history, 'imageStorage' => new PreviewFixtures(__DIR__, $root), 'translator' => new Translator('nl')] as $name => $value) {
    $reflection->getProperty($name)->setValue($output, $value);
}
check(invoke($output, 'annotationManifestUrl', $report) === 'https://images.invalid/a/front/info.json', 'The viewer uses the saved IIIF service.');
check(invoke($output, 'reportMainImageSource', $report, false) === $version->getImages()[0]['image_url'], 'The header uses a preview URL.');
check(str_starts_with(invoke($output, 'reportMainImageSource', $report, true), 'data:'), 'PDF rendering can embed a bounded preview.');
$context = invoke($output, 'damageAnnotationContext', $report, 'wrong-current-image.jpg', [], [], false);
check($context['sources'][0]['imageSource'] === $version->getImages()[1]['image_url'], 'The second canvas keeps its own image URL.');
check($context['sources'][0]['width'] === 6979.0, 'Preview retains the original coordinate dimensions.');

foreach (['old_browser', 'database', 'csrf', 'payload', 'conflict', 'data_uri', 'wrong_canvas'] as $scenario) {
    [$manager, $history, $controller, $report, $damage] = fixture();
    $payload = payload($history, $report, $damage);
    if ($scenario === 'old_browser') unset($payload['mainImageSources']);
    if ($scenario === 'database') $manager->getConnection()->executeStatement('DROP TABLE annotations');
    if ($scenario === 'payload') unset($payload['annotation']['target']['selector']);
    if ($scenario === 'conflict') $payload['mainImageFingerprint'] = 'stale';
    if ($scenario === 'data_uri') $payload['mainImageSources'][0]['url'] = 'data:image/png;base64,not-an-image-url';
    if ($scenario === 'wrong_canvas') $payload['sourceKey'] = 'main:3';
    try {
        $response = $controller->saveAnnotation($report, request($payload, $scenario === 'csrf' ? 'invalid' : 'fixture'));
        check(in_array($scenario, ['old_browser', 'database'], true), 'Expected the request to be rejected.');
    } catch (AccessDeniedException) {
        check($scenario === 'csrf', 'CSRF remains enforced.');
        continue;
    } catch (HttpExceptionInterface $exception) {
        check($exception->getStatusCode() === ['payload' => 400, 'conflict' => 409, 'data_uri' => 400, 'wrong_canvas' => 409][$scenario], 'Keep validation and conflict statuses.');
        continue;
    }
    if ($scenario === 'old_browser') {
        check($response->getStatusCode() === 201, 'Existing browser sessions can save without a new field.');
        check($history->forReport($report)['manifestUrl'] === 'https://images.invalid/a/manifest', 'Old clients still pin the manifest URL.');
        $payload['mainImageSources'] = payload($history, $report, $damage)['mainImageSources'];
        check($controller->saveAnnotation($report, request($payload))->getStatusCode() === 200, 'A newer client can add missing per-canvas URLs.');
        check(count($history->forReport($report)['images']) === 2, 'Per-canvas URLs enrich a source-only snapshot.');
        continue;
    }
    $result = json_decode($response->getContent(), true);
    check($response->getStatusCode() === 500 && $result['error'] === 'annotation_save_failed', 'Database errors retain safe diagnostics.');
    check($result['diagnostics']['stage'] === 'save_annotation' && $result['diagnostics']['sqlState'] === 'HY000', 'Identify the failing database operation.');
    check(!str_contains($response->getContent(), 'SELECT') && !str_contains($response->getContent(), 'https:'), 'No query or URL in error diagnostics.');
    check(!$manager->getConnection()->isTransactionActive(), 'Failed saves roll back.');
    foreach (['report_main_images', 'object_image_versions'] as $table) {
        check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Failed saves leave no URL history rows.');
    }
}
echo "PASS: URL-only annotation/history/sync, multi-canvas references, previews, old clients, validation, database diagnostics and rollback.\n";

