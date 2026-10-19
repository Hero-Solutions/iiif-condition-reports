<?php

declare(strict_types=1);

// Isolated SQLite and fake IIIF responses only. No kernel, environment files or HTTP.
use App\Command\HarvestDatahubObjectsCommand;
use App\Controller\{ReportAnnotationController, ReportController};
use App\Datahub\LidoMeasurements;
use App\Entity\{Annotation, DamageCase, IIIFManifest, ObjectImageVersion, ObjectManifest, ObjectRecord, Report, ReportImage, ReportMainImage, ReportSeries};
use App\Service\{MainImageHistory, ObjectChangeLogger, ObjectImageStorage, ReportAuthorProvider};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\{Container, ParameterBag\ParameterBag};
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
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

final class ImageFixtures extends ObjectImageStorage
{
    public array $documents = [];
    public array $copies = [];
    public bool $fail = false;
    public ?Throwable $failure = null;
    public function readIiifJson(string $url): array
    {
        return $this->documents[$url] ?? throw new RuntimeException('Unexpected fixture URL: ' . $url);
    }
    public function storeArchiveImage(string $url, string $thumbnailUrl): array
    {
        if ($this->failure !== null) throw $this->failure;
        if ($this->fail) throw new RuntimeException('Fixture download failure.');
        check(str_starts_with($url, 'https://images.invalid/'), 'Only fixture images may be copied.');
        $this->copies[] = [$url, $thumbnailUrl];
        return ['image_url' => '/uploads/objects/archive-' . count($this->copies) . '.jpg', 'thumbnail_url' => '/uploads/objects/thumb-' . count($this->copies) . '.jpg'];
    }
    public function dataUri(?string $url): ?string { return 'data:image/jpeg;base64,' . base64_encode((string) $url); }
    public function externalDataUri(string $url): string { throw new RuntimeException('Historical output must not contact an external source.'); }
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
$storage = new ImageFixtures(__DIR__, $root);
$storage->documents = [
    'https://images.invalid/a/manifest' => ['sequences' => [['canvases' => [
        ['images' => [['resource' => ['service' => ['@id' => 'https://images.invalid/a/front']]]]],
        ['images' => [['resource' => ['service' => ['@id' => 'https://images.invalid/a/back']]]]],
    ]]]],
    'https://images.invalid/a/front/info.json' => ['@context' => 'http://iiif.io/api/image/2/context.json'],
    'https://images.invalid/a/back/info.json' => ['type' => 'ImageService3'],
    'https://images.invalid/b/info.json' => ['type' => 'ImageService3'],
];
$history = new MainImageHistory($manager, $storage);
$object = (new ObjectRecord('IMAGE-TEST', 'Fixture'))->applyDatahubSync('IMAGE-TEST', 'fixture', [
    'iiif_manifest_url' => 'https://images.invalid/a/manifest',
    'iiif_image_info_url' => 'https://images.invalid/a/front/info.json',
]);
$manifest = (new IIIFManifest())->setManifestId('https://images.invalid/a/manifest')->setSourceUrl('https://images.invalid/a/manifest');
$sourceLink = new ObjectManifest($object, $manifest, ObjectManifest::ROLE_SOURCE);
$series = new ReportSeries($object);
$first = new Report($series);
$existing = new Report($series);
$empty = new Report($series);
$extraOnly = new Report($series);
$undone = new Report($series);
foreach ([$object, $manifest, $sourceLink, $series, $first, $existing, $empty, $extraOnly, $undone] as $entity) $manager->persist($entity);
$manager->flush();
$originalFingerprint = $history->forReport($first)['fingerprint'];
check($storage->copies === [], 'Viewing an image without annotations must not create a backup.');

$case = new DamageCase($first, 'fixture-case', 'Fixture damage', '#ff0000');
$manager->persist($case);
$manager->flush();
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
$controller = new ReportAnnotationController($manager, new ReportAuthorProvider($manager), $history);
$controller->setContainer($container);
$target = ['selector' => ['type' => 'SvgSelector', 'value' => '<svg><path d="M1 1L20 20"/></svg>'], 'sourceDimensions' => ['width' => 1000, 'height' => 800]];
$request = Request::create('/fixture', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'fixture'], content: json_encode([
    'damageCaseId' => $case->getId(), 'sourceKey' => 'main:2', 'mainImageFingerprint' => $originalFingerprint,
    'annotation' => ['id' => 'line-1', 'target' => $target, 'bodies' => []],
]));
$response = $controller->saveAnnotation($first, $request);
check($response->getStatusCode() === 201 && count($storage->copies) === 2, 'First drawing stores every original canvas once.');
check($storage->copies[0][0] === 'https://images.invalid/a/front/full/full/0/default.jpg', 'IIIF 2 archives full resolution.');
check($storage->copies[1][0] === 'https://images.invalid/a/back/full/max/0/default.jpg', 'IIIF 3 archives full resolution.');
$version = $manager->getRepository(ReportMainImage::class)->findOneBy(['report' => $first])->getImageVersion();
$savedTarget = $manager->getRepository(Annotation::class)->findOneBy(['report' => $first])->getTarget();
$first->finalize(1);

// Already-existing annotations are captured before sync replaces their source; extra photos do not count.
foreach ([$existing, $extraOnly, $undone] as $report) {
    $damage = new DamageCase($report, 'case-' . $report->getId(), 'Damage', '#ff0000');
    $annotation = (new Annotation($report, $damage, 'main:1', 'line-' . $report->getId()))->setTarget($target);
    if ($report === $extraOnly) {
        $image = new ReportImage($report, '/fixture-photo.jpg', ReportImage::SOURCE_UPLOAD);
        $manager->persist($image);
        $annotation->setReportImage($image)->setSourceKey('report-image-1');
    }
    if ($report === $undone) {
        $history->preserveForAnnotation($report, $originalFingerprint, 'main:1');
        $annotation->delete();
    }
    $manager->persist($damage);
    $manager->persist($annotation);
}
$manager->flush();
check(count($storage->copies) === 2, 'Reports using the same image share the stored files.');

function syncImage(EntityManager $manager, MainImageHistory $history, array $source, string $date): void
{
    $command = new HarvestDatahubObjectsCommand($manager, new ParameterBag(), new ObjectChangeLogger($manager), new LidoMeasurements(), $history);
    $prepared = ['inventory_number' => 'IMAGE-TEST', 'record_identifier' => 'fixture', 'source_data' => $source, 'synced_at' => new DateTimeImmutable($date)];
    $manager->wrapInTransaction(function () use ($command, $prepared): void {
        invoke($command, 'preloadBatch', [$prepared]);
        invoke($command, 'syncPreparedRecord', $prepared);
    });
}

syncImage($manager, $history, ['iiif_manifest_url' => 'https://images.invalid/b/manifest', 'iiif_image_info_url' => 'https://images.invalid/b/info.json'], '2026-10-14 12:00');
check(count($storage->copies) === 2, 'A changed URL reuses the stored copy, without re-downloading the old source.');
check($history->forReport($first)['images'] === $version->getImages(), 'Final report keeps its original image.');
check($history->forReport($existing)['images'] === $version->getImages(), 'Existing draft annotations keep their original image too.');
foreach ([$empty, $extraOnly, $undone] as $report) {
    check($history->forReport($report)['manifestUrl'] === 'https://images.invalid/b/manifest', 'Unannotated/undone/extra-photo-only reports follow the current image.');
}
check($manager->getRepository(Annotation::class)->findOneBy(['report' => $first])->getTarget() === $savedTarget, 'Sync must not transform annotation geometry.');
check($first->getStatus() === Report::STATUS_FINALIZED, 'Sync must not change report status.');
$thumbs = $history->thumbnailsForReports([$first, $empty], [$object->getId() => '/current.jpg']);
check($thumbs[$first->getId()] === $version->getImages()[0]['thumbnail_url'] && $thumbs[$empty->getId()] === '/current.jpg', 'Report list thumbnails also respect image versions.');
check(count($history->previousImages($object)) === 1 && $version->getReplacedAt()->format('H:i') === '12:00', 'Object history shows one version with its sync discovery date.');

syncImage($manager, $history, ['iiif_manifest_url' => 'https://images.invalid/b/manifest', 'iiif_image_info_url' => 'https://images.invalid/b/info.json'], '2026-10-15');
syncImage($manager, $history, ['iiif_manifest_url' => 'https://images.invalid/c/manifest', 'iiif_image_info_url' => 'https://images.invalid/c/info.json'], '2026-10-16');
check(count($storage->copies) === 2 && count($history->previousImages($object)) === 1, 'Identical syncs and unannotated intermediate images do not create history.');
check($version->getReplacedAt()->format('Y-m-d') === '2026-10-14', 'Do not overwrite the original replacement date.');
$newReport = (new Report($series))->setBasedOnReport($first);
check($history->forReport($newReport)['manifestUrl'] === 'https://images.invalid/c/manifest', 'A new report based on an old report starts with the current image.');

// Editor source identities, header/preview/PDF and per-canvas annotation backgrounds.
$reflection = new ReflectionClass(ReportController::class);
$reportController = $reflection->newInstanceWithoutConstructor();
foreach (['entityManager' => $manager, 'mainImages' => $history, 'imageStorage' => $storage, 'translator' => new Translator('nl')] as $name => $value) {
    $reflection->getProperty($name)->setValue($reportController, $value);
}
check(invoke($reportController, 'annotationManifestUrl', $first) === null, 'Historical zoom must never fetch the live manifest.');
check(invoke($reportController, 'reportMainImageSource', $first, false) === $version->getImages()[0]['image_url'], 'Report header uses the original photo.');
check(str_starts_with(invoke($reportController, 'reportMainImageSource', $first, true), 'data:'), 'PDF embeds the local historical photo.');
$damageOutput = invoke($reportController, 'damageAnnotationContext', $first, 'wrong-current-image.jpg', [], [], false);
check($damageOutput['sources'][0]['imageSource'] === $version->getImages()[1]['image_url'], 'The second canvas retains its own background, not the first or current photo.');
check($damageOutput['sources'][0]['width'] === 1000.0, 'Original annotation coordinates remain unchanged.');

try {
    $history->preserveForAnnotation($empty, $originalFingerprint, 'main:1');
    throw new RuntimeException('Stale page was accepted.');
} catch (ConflictHttpException) {}

// Info URL changes also count, even when the manifest URL stays unchanged.
$storage->documents['https://images.invalid/c/manifest'] = ['items' => [['items' => [['items' => [['body' => [
    'type' => 'Image', 'service' => [['id' => 'https://images.invalid/b', 'type' => 'ImageService3']],
]]]]]]]];
$history->preserveForAnnotation($empty, $history->forReport($empty)['fingerprint'], 'main');
$damage = new DamageCase($empty, 'new-case', 'Damage', '#0000ff');
$annotation = (new Annotation($empty, $damage, 'main', 'new-line'))->setTarget($target);
$manager->persist($damage);
$manager->persist($annotation);
$manager->flush();
syncImage($manager, $history, ['iiif_manifest_url' => 'https://images.invalid/c/manifest', 'iiif_image_info_url' => 'https://images.invalid/d/info.json'], '2026-10-17');
check(count($history->previousImages($object)) === 2, 'Changed IIIF image service with unchanged manifest is archived.');

// If an old annotated image cannot be copied, the whole sync transaction leaves source and annotations intact.
$unprotected = new Report($series);
$damage = new DamageCase($unprotected, 'unprotected', 'Damage', '#000000');
$annotation = (new Annotation($unprotected, $damage, 'main', 'unprotected-line'))->setTarget($target);
foreach ([$unprotected, $damage, $annotation] as $entity) $manager->persist($entity);
$manager->flush();
$storage->fail = true;
try {
    syncImage($manager, $history, ['iiif_manifest_url' => 'https://images.invalid/e/manifest'], '2026-10-18');
    throw new LogicException('Failed image preservation must stop replacement.');
} catch (RuntimeException $exception) {
    check(str_contains($exception->getMessage(), 'Fixture download failure'), 'Expected the controlled archive failure.');
}
$source = json_decode($manager->getConnection()->fetchOne('SELECT source_data FROM object_records WHERE id = ?', [$object->getId()]), true);
check($source['iiif_image_info_url'] === 'https://images.invalid/d/info.json', 'Failed sync keeps the previous source data in the database.');
// Exercise the actual POST handler with a polygon and isolated failures, including rollback.
foreach (['success', 'download', 'download_http', 'database', 'csrf', 'payload', 'conflict'] as $scenario) {
    $manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
    $storage = new ImageFixtures(__DIR__, $root);
    $history = new MainImageHistory($manager, $storage);
    $object = (new ObjectRecord('ERROR-TEST', 'Fixture'))->setImageUrl('https://images.invalid/original.jpg');
    $series = new ReportSeries($object);
    $report = new Report($series);
    $damage = new DamageCase($report, 'fixture-case', 'Fixture damage', '#ff0000');
    foreach ([$object, $series, $report, $damage] as $entity) $manager->persist($entity);
    $manager->flush();
    $controller = new ReportAnnotationController($manager, new ReportAuthorProvider($manager), $history);
    $controller->setContainer($container);
    $payload = [
        'damageCaseId' => $damage->getId(), 'sourceKey' => 'main', 'reportImageId' => null,
        'mainImageFingerprint' => $history->forReport($report)['fingerprint'],
        'annotation' => ['id' => 'fixture-polygon', 'bodies' => [], 'target' => [
            'annotation' => 'fixture-polygon',
            'selector' => ['type' => 'POLYGON', 'geometry' => [
                'points' => [[10.5, 20.5], [30.5, 20.5], [30.5, 40.5], [10.5, 20.5]],
                'bounds' => ['minX' => 10.5, 'minY' => 20.5, 'maxX' => 30.5, 'maxY' => 40.5],
            ]],
            'sourceDimensions' => ['width' => 6979, 'height' => 4949],
        ]],
    ];
    if ($scenario === 'download') $storage->failure = new FileException('Fixture private URL https://images.invalid/?token=fixture-secret', 60);
    if ($scenario === 'download_http') $storage->failure = new FileException('The external image returned HTTP status 503.');
    if ($scenario === 'database') $manager->getConnection()->executeStatement('DROP TABLE annotations');
    if ($scenario === 'payload') unset($payload['annotation']['target']['selector']);
    if ($scenario === 'conflict') $payload['mainImageFingerprint'] = 'stale-fixture';
    $request = Request::create('/fixture', 'POST', server: ['HTTP_X_CSRF_TOKEN' => $scenario === 'csrf' ? 'invalid' : 'fixture'], content: json_encode($payload));

    try {
        $response = $controller->saveAnnotation($report, $request);
        check(!in_array($scenario, ['csrf', 'payload', 'conflict'], true), 'Expected an unchanged HTTP exception.');
    } catch (AccessDeniedException) {
        check($scenario === 'csrf', 'Invalid CSRF tokens remain denied before storage.');
        continue;
    } catch (HttpExceptionInterface $exception) {
        check($exception->getStatusCode() === ['payload' => 400, 'conflict' => 409][$scenario], 'Keep the original HTTP error status.');
        continue;
    }

    $result = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    if ($scenario === 'success') {
        check($response->getStatusCode() === 201, 'A main-image polygon is accepted.');
        check($result['annotation']['target'] === $payload['annotation']['target'], 'Polygon coordinates and dimensions remain unchanged.');
        continue;
    }
    check($response->getStatusCode() === 500 && $result['error'] === 'annotation_save_failed', 'Storage errors return structured JSON with HTTP 500.');
    check($response->headers->hasCacheControlDirective('no-store'), 'Failure diagnostics must not be cached.');
    check(!str_contains($response->getContent(), 'fixture-secret') && !str_contains($response->getContent(), 'https:') && !str_contains($response->getContent(), 'SELECT'), 'Do not expose URLs, exception messages or SQL.');
    $details = $result['diagnostics'];
    check(!str_contains($details['file'], '/') && !str_contains($details['file'], '\\') && $details['line'] > 0, 'Return only the filename and line.');
    check($details['stage'] === ($scenario === 'database' ? 'save_annotation' : 'preserve_main_image'), 'Identify the failing operation.');
    if ($scenario === 'download') {
        check($details['exception'] === 'FileException' && $details['code'] === 60, 'Preserve the download error code.');
        if (function_exists('curl_strerror')) check($details['downloadError'] === curl_strerror(60), 'Only expose the fixed cURL description.');
    }
    if ($scenario === 'download_http') check($details['downloadHttpStatus'] === 503, 'Expose the remote HTTP status without its body or URL.');
    if ($scenario === 'database') check($details['sqlState'] === 'HY000', 'Expose the database SQLSTATE without its query.');
    check(!$manager->getConnection()->isTransactionActive(), 'Failed saves roll back the transaction.');
    foreach (['report_main_images', 'object_image_versions'] as $table) {
        check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table) === 0, 'Failed saves must not persist image-version rows.');
    }
    if ($scenario !== 'database') check((int) $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM annotations') === 0, 'Failed downloads must not persist annotations.');
}

echo "PASS: first-drawing archive, shared files, v2/v3 and multi-canvas sources, sync/unchanged/empty/undone reports, stale page rejection, preview/PDF/zoom, polygon POST, sanitized error diagnostics, HTTP statuses and transactional failure.\n";
