<?php

declare(strict_types=1);

// Isolated SQLite, fake authorization/CSRF and disposable local files. No app bootstrap or network.
use App\Controller\ReportController;
use App\Entity\{Annotation, DamageCase, ObjectRecord, Project, Report, ReportImage, ReportSeries};
use App\Service\ReportImageStorage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\{Generator\UrlGenerator, RequestContext, Route, RouteCollection};
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\{CsrfToken, CsrfTokenManagerInterface};

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

function rejects(callable $action, string $exceptionClass): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        check($exception instanceof $exceptionClass, 'Unexpected rejection: ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $exceptionClass);
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [
    ObjectRecord::class, Project::class, ReportSeries::class, Report::class,
    ReportImage::class, DamageCase::class, Annotation::class,
]));
$manager->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
$object = (new ObjectRecord('PHOTO-TEST', 'Fixture'))->setImageUrl('/uploads/objects/main.jpg');
$series = new ReportSeries($object);
$report = new Report($series);
$otherReport = new Report($series);
$photo = (new ReportImage($report, '/uploads/reports/images/photo.jpg'))->setThumbnailPath('/uploads/reports/images/thumb.jpg');
$schema = new ReportImage($report, '/schema.svg', ReportImage::SOURCE_SCHEMA);
$otherPhoto = new ReportImage($otherReport, '/uploads/reports/images/other.jpg');
$damage = new DamageCase($report, 'fixture-case', 'Scratch');
$photoAnnotation = (new Annotation($report, $damage, 'report-image', 'fixture-photo'))->setReportImage($photo);
$mainAnnotation = new Annotation($report, $damage, 'main', 'fixture-main');
foreach ([$object, $series, $report, $otherReport, $photo, $schema, $otherPhoto, $damage, $photoAnnotation, $mainAnnotation] as $entity) $manager->persist($entity);
$manager->flush();

$authorization = new class implements AuthorizationCheckerInterface {
    public bool $readOnly = false;
    public function isGranted(mixed $attribute, mixed $subject = null): bool { return $this->readOnly; }
};
$csrf = new class implements CsrfTokenManagerInterface {
    public function getToken(string $tokenId): CsrfToken { return new CsrfToken($tokenId, 'fixture-' . $tokenId); }
    public function refreshToken(string $tokenId): CsrfToken { return $this->getToken($tokenId); }
    public function removeToken(string $tokenId): ?string { return null; }
    public function isTokenValid(CsrfToken $token): bool { return $token->getValue() === 'fixture-' . $token->getId(); }
};
$routes = new RouteCollection();
$routes->add('reports_edit', new Route('/{_locale}/reports/{id}/edit'));
$container = new Container();
$container->set('security.authorization_checker', $authorization);
$container->set('security.csrf.token_manager', $csrf);
$container->set('router', new UrlGenerator($routes, new RequestContext()));
$reflection = new ReflectionClass(ReportController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('entityManager')->setValue($controller, $manager);
$controller->setContainer($container);
$directory = __DIR__ . '/image-delete-fixture-' . bin2hex(random_bytes(6));
mkdir($directory);
$reflection->getProperty('reportImageStorage')->setValue($controller, new ReportImageStorage($directory, $directory));
$request = static function (ReportImage $image, ?string $token = null) use ($report): Request {
    $request = Request::create('/fixture/delete', 'POST', ['_token' => $token ?? 'fixture-report_image_' . $image->getId() . '_' . $report->getId()]);
    $request->setLocale('nl');
    return $request;
};
$files = ['photo.jpg', 'thumb.jpg', 'main.jpg', 'schema.svg', 'other.jpg'];
try {
    foreach ($files as $file) file_put_contents($directory . '/' . $file, 'Disposable deletion fixture.');
    rejects(fn () => $controller->deleteImage($report->getId(), $photo->getId(), $request($photo, 'invalid')), AccessDeniedException::class);
    rejects(fn () => $controller->deleteImage($report->getId(), $otherPhoto->getId(), $request($otherPhoto)), NotFoundHttpException::class);
    rejects(fn () => $controller->deleteImage($report->getId(), 999999, $request($photo)), NotFoundHttpException::class);
    $authorization->readOnly = true;
    rejects(fn () => $controller->deleteImage($report->getId(), $photo->getId(), $request($photo)), AccessDeniedException::class);
    $authorization->readOnly = false;
    check(is_file($directory . '/photo.jpg') && !$photoAnnotation->isDeleted(), 'Rejected requests must leave the photo and annotations intact.');

    $photoId = $photo->getId();
    $response = $controller->deleteImage($report->getId(), $photoId, $request($photo));
    check(str_contains($response->headers->get('Location'), 'tab=damage'), 'Return to the image editor after deletion.');
    check($manager->find(ReportImage::class, $photoId) === null, 'Remove the selected photo record.');
    check(!is_file($directory . '/photo.jpg') && !is_file($directory . '/thumb.jpg'), 'Remove only the selected photo and thumbnail files.');
    $manager->refresh($photoAnnotation);
    check($photoAnnotation->isDeleted() && $photoAnnotation->getReportImage() === null, 'Retain deleted annotation history without a dangling image link.');
    check(!$mainAnnotation->isDeleted() && $object->getImageUrl() === '/uploads/objects/main.jpg', 'Protect the main image and its annotations.');
    check(is_file($directory . '/main.jpg') && is_file($directory . '/other.jpg'), 'Keep the main image and other report files.');

    $controller->deleteImage($report->getId(), $schema->getId(), $request($schema));
    check(is_file($directory . '/schema.svg'), 'Removing a frame sketch never deletes the shared schema file.');
    $protected = new ReportImage($report, '/uploads/reports/images/main.jpg');
    $manager->persist($protected);
    $manager->flush();
    $report->finalize(1);
    rejects(fn () => $controller->deleteImage($report->getId(), $protected->getId(), $request($protected)), AccessDeniedException::class);
    $report->archive();
    rejects(fn () => $controller->deleteImage($report->getId(), $protected->getId(), $request($protected)), AccessDeniedException::class);
    check(is_file($directory . '/main.jpg'), 'Finalized and archived reports remain unchanged.');
    echo "PASS: photo deletion, annotation history, main image preservation, report ownership, CSRF, read-only users and finalized/archived reports.\n";
} finally {
    foreach ($files as $file) if (is_file($directory . '/' . $file)) unlink($directory . '/' . $file);
    rmdir($directory);
}
