<?php

declare(strict_types=1);

// Isolated in-memory database and disposable local files. No app bootstrap or network.
use App\Controller\ReportController;
use App\Entity\{Annotation, DamageCase, ObjectImageVersion, ObjectRecord, Project, Report, ReportDocument, ReportImage, ReportMainImage, ReportSeries};
use App\Service\{MainImageHistory, ProjectReportDefaults, ReportDocumentStorage, ReportImageStorage, ReportMeasurementDefaults};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\{Generator\UrlGenerator, RequestContext, Route, RouteCollection};
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
$loader->register();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$manager = new EntityManager($connection, $config);
(new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
$connection->executeStatement('PRAGMA foreign_keys = ON');

$object = (new ObjectRecord('FIXTURE.1', 'Fixture work'))->setImageUrl('https://images.invalid/current.jpg');
$firstLoan = new Project('First loan', Project::TYPE_LOAN);
$secondLoan = new Project('Second loan', Project::TYPE_LOAN);
$firstSeries = new ReportSeries($object, '', $firstLoan);
$secondSeries = new ReportSeries($object, '', $secondLoan);
$source = (new Report($firstSeries, Report::TYPE_INCOMING_CONDITION))->setData(['condition' => 'Fixture condition']);
$source->finalize(null);
$photo = (new ReportImage($source, '/uploads/reports/images/photo.jpg'))
    ->setThumbnailPath('/uploads/reports/images/thumb.jpg')->setOriginalName('detail.jpg');
$schema = new ReportImage($source, '/schema.svg', ReportImage::SOURCE_SCHEMA);
$document = (new ReportDocument($source, '/uploads/reports/documents/notes.txt', 'notes.txt', 'text/plain'))
    ->setSection(ReportDocument::SECTION_RECOMMENDATIONS);
$case = (new DamageCase($source, 'scratch', 'Scratch'))->setLegendNote('Fixture legend')->setStrokeWidth(4);
$removedCase = (new DamageCase($source, 'tear', 'Tear'))->delete();
$annotation = (new Annotation($source, $case, 'report-image', 'detail'))
    ->setReportImage($photo)->setCreatedById(42)->setTarget(['selector' => ['type' => 'SvgSelector']]);
$mainAnnotation = new Annotation($source, $case, 'main:2', 'overview');
$schemaAnnotation = (new Annotation($source, $case, 'report-image', 'schema'))->setReportImage($schema);
$removedAnnotation = (new Annotation($source, $case, 'main', 'removed'))->delete();
$removedCaseAnnotation = new Annotation($source, $removedCase, 'main', 'removed-case');
$version = new ObjectImageVersion($object, str_repeat('a', 64), [], [
    ['key' => 'main:1', 'image_url' => 'https://images.invalid/front.jpg', 'thumbnail_url' => ''],
    ['key' => 'main:2', 'image_url' => 'https://images.invalid/back.jpg', 'thumbnail_url' => ''],
]);
foreach ([$object, $firstLoan, $secondLoan, $firstSeries, $secondSeries, $source, $photo, $schema, $document,
    $case, $removedCase, $annotation, $mainAnnotation, $schemaAnnotation, $removedAnnotation, $removedCaseAnnotation,
    $version, new ReportMainImage($source, $version)] as $entity) {
    $manager->persist($entity);
}
$manager->flush();

$routes = new RouteCollection();
$routes->add('reports_edit', new Route('/{_locale}/reports/{id}/edit'));
$container = new Container();
$container->set('router', new UrlGenerator($routes, new RequestContext()));
$container->set('security.token_storage', new TokenStorage());
$container->set('security.authorization_checker', new class implements AuthorizationCheckerInterface {
    public function isGranted(mixed $attribute, mixed $subject = null): bool { return false; }
});
$reflection = new ReflectionClass(ReportController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$controller->setContainer($container);
$directory = __DIR__ . '/report-follow-up-fixture-' . bin2hex(random_bytes(6));
mkdir($directory);
$history = new MainImageHistory($manager);
foreach (['entityManager' => $manager, 'mainImages' => $history, 'projectReportDefaults' => new ProjectReportDefaults(),
    'measurementDefaults' => new ReportMeasurementDefaults(), 'reportImageStorage' => new ReportImageStorage($directory, $root),
    'reportDocumentStorage' => new ReportDocumentStorage($directory)] as $property => $value) {
    $reflection->getProperty($property)->setValue($controller, $value);
}

$request = Request::create('/fixture/follow-up', 'POST');
$request->setLocale('nl');
$reports = $manager->getRepository(Report::class);
$images = $manager->getRepository(ReportImage::class);
$documents = $manager->getRepository(ReportDocument::class);
$annotations = $manager->getRepository(Annotation::class);

try {
    foreach (['photo.jpg' => 'Photo', 'thumb.jpg' => 'Thumbnail', 'notes.txt' => 'Document'] as $name => $content) {
        file_put_contents($directory . '/' . $name, $content);
    }

    $response = $controller->new($firstSeries->getId(), $request);
    $draft = $reports->findOneBy(['series' => $firstSeries, 'status' => Report::STATUS_ACTIVE]);
    check($draft?->getBasedOnReport() === $source && $draft->getData() === $source->getData(), 'Previous report fields were lost.');
    check($draft->getType() === Report::TYPE_INCOMING_CONDITION, 'Existing phase defaults changed.');
    check($controller->new($firstSeries->getId(), $request)->getTargetUrl() === $response->getTargetUrl(), 'Retry created another draft.');

    $photoCopy = $images->findOneBy(['report' => $draft, 'source' => ReportImage::SOURCE_UPLOAD]);
    $schemaCopy = $images->findOneBy(['report' => $draft, 'source' => ReportImage::SOURCE_SCHEMA]);
    $documentCopy = $documents->findOneBy(['report' => $draft]);
    check($photoCopy->getPath() !== $photo->getPath() && $photoCopy->getThumbnailPath() !== $photo->getThumbnailPath(), 'Photo files are shared.');
    check(file_get_contents($directory . '/' . basename($photoCopy->getPath())) === 'Photo', 'Photo bytes were not copied.');
    check(file_get_contents($directory . '/' . basename($photoCopy->getThumbnailPath())) === 'Thumbnail', 'Thumbnail bytes were not copied.');
    check($schemaCopy->getId() !== $schema->getId() && $schemaCopy->getPath() === $schema->getPath(), 'Sketch was not copied.');
    check($documentCopy->getPath() !== $document->getPath() && $documentCopy->getSection() === $document->getSection(), 'Document was not copied.');
    check(file_get_contents($directory . '/' . basename($documentCopy->getPath())) === 'Document', 'Document bytes were not copied.');

    $detail = $annotations->findOneBy(['report' => $draft, 'clientId' => 'detail']);
    $main = $annotations->findOneBy(['report' => $draft, 'clientId' => 'overview']);
    $sketch = $annotations->findOneBy(['report' => $draft, 'clientId' => 'schema']);
    check($detail->getReportImage() === $photoCopy && $detail->getSourceKey() === 'report-image-' . $photoCopy->getId(), 'Photo annotation points at the old report.');
    check($sketch->getReportImage() === $schemaCopy && $sketch->getSourceKey() === 'report-image-' . $schemaCopy->getId(), 'Sketch annotation points at the old report.');
    check($detail->getTarget() === $annotation->getTarget() && $detail->getCreatedById() === 42, 'Annotation geometry or attribution was lost.');
    check($detail->getDamageCase()->getLegendNote() === 'Fixture legend' && $detail->getDamageCase()->getStrokeWidth() === 4.0, 'Damage legend was lost.');
    check($main->getSourceKey() === 'main:2' && $history->forReport($draft)['images'] === $version->getImages(), 'Annotation no longer points to its original canvas.');
    check($annotations->count(['report' => $draft]) === 3, 'Deleted damage reappeared.');

    $detail->delete();
    $draft->setType(Report::TYPE_RETURNING_CONDITION)->finalize(null);
    $manager->flush();
    $controller->new($secondSeries->getId(), $request);
    $next = $reports->findOneBy(['series' => $secondSeries, 'status' => Report::STATUS_ACTIVE]);
    check($next?->getBasedOnReport() === $draft && $next->getProject() === $secondLoan, 'A new project did not use the latest report of the object.');
    check($annotations->count(['report' => $next]) === 2 && !$annotation->isDeleted(), 'Restored damage returned or the original report changed.');
    check($images->count(['report' => $next]) === 2 && $documents->count(['report' => $next]) === 1, 'Attachments did not carry over across projects.');

    $next->finalize(null);
    $manager->persist(new ReportDocument($next, '/uploads/reports/documents/missing.txt', 'missing.txt', 'text/plain'));
    $manager->flush();
    $filesBefore = glob($directory . '/*');
    try {
        $controller->new($secondSeries->getId(), $request);
        throw new RuntimeException('Expected a missing-file failure.');
    } catch (FileException) {
        check((int) $connection->fetchOne('SELECT COUNT(*) FROM reports') === 3, 'A partially copied report was committed.');
        check(glob($directory . '/*') === $filesBefore, 'Files from a failed copy were left behind.');
    }
} finally {
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

echo "PASS: same-project and cross-project follow-ups, independent media, damage history, and rollback.\n";

