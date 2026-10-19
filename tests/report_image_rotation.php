<?php

declare(strict_types=1);

// In-memory SQLite and generated local GD images only. No application bootstrap, credentials or network.
use App\Controller\ReportController;
use App\Entity\{Annotation, DamageCase, ObjectRecord, Project, Report, ReportImage, ReportSeries};
use App\Service\{AnnotationRotation, ReportImageStorage};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\{Request, RequestStack, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\{Generator\UrlGenerator, RequestContext, Route, RouteCollection};
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
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

function box(int $x1, int $y1, int $x2, int $y2): array
{
    return ['minX' => $x1, 'minY' => $y1, 'maxX' => $x2, 'maxY' => $y2];
}

$rotation = new AnnotationRotation();
$polygon = ['type' => 'POLYGON', 'geometry' => [
    'points' => [[12, 8], [32, 8], [32, 24]], 'bounds' => box(12, 8, 32, 24),
]];
$ellipse = ['type' => 'ELLIPSE', 'geometry' => [
    'cx' => 23, 'cy' => 35, 'rx' => 6, 'ry' => 3, 'bounds' => box(17, 32, 29, 38),
]];
$multi = ['type' => 'MULTIPOLYGON', 'geometry' => [
    'polygons' => [
        ['rings' => [
            ['points' => [[10, 10], [50, 10], [50, 50], [10, 50]], 'bounds' => box(10, 10, 50, 50)],
            ['points' => [[20, 20], [20, 30], [30, 30], [30, 20]], 'bounds' => box(20, 20, 30, 30)],
        ], 'bounds' => box(10, 10, 50, 50)],
        ['rings' => [['points' => [[80, 5], [90, 5], [90, 15]]]], 'bounds' => box(80, 5, 90, 15)],
    ], 'bounds' => box(10, 5, 90, 50),
]];
$target = static fn (array $selector): array => [
    'annotation' => 'fixture', 'selector' => $selector,
    'sourceDimensions' => ['width' => 112, 'height' => 72],
];
$result = $rotation->rotate($target($polygon), 112, 72);
check($result['selector']['geometry']['points'] == [[64, 12], [64, 32], [48, 32]], 'Rotate around the original image height, not width or annotation bounds.');
check($result['selector']['geometry']['bounds'] == box(48, 12, 64, 32), 'Update selection bounds.');
$result = $rotation->rotate($target($ellipse), 112, 72);
check($result['selector']['geometry'] == ['cx' => 37, 'cy' => 23, 'rx' => 3, 'ry' => 6, 'bounds' => box(34, 17, 40, 29)], 'Rotate ellipse centers and swap radii.');
$result = $rotation->rotate($target($multi), 112, 72);
check($result['selector']['geometry']['polygons'][0]['rings'][1]['points'] == [[52, 20], [42, 20], [42, 30], [52, 30]], 'Keep erased holes in their rotated positions.');
check($result['selector']['geometry']['polygons'][1]['bounds'] == box(57, 80, 67, 90), 'Rotate every disconnected polygon and its bounds.');
check($result['selector']['geometry']['bounds'] == box(22, 10, 67, 90), 'Update the full multipolygon bounds.');
$result = $rotation->rotate($target($polygon), 112, 72, -90);
check($result['selector']['geometry']['points'] == [[8, 100], [8, 80], [24, 80]], 'Left rotation uses the original width for the new vertical coordinates.');
check($result['selector']['geometry']['bounds'] == box(8, 80, 24, 100), 'Update bounds when turning left.');
$result = $rotation->rotate($target($ellipse), 112, 72, -90);
check($result['selector']['geometry'] == ['cx' => 35, 'cy' => 89, 'rx' => 3, 'ry' => 6, 'bounds' => box(32, 83, 38, 95)], 'Rotate ellipse centers and radii counterclockwise.');
foreach ([$polygon, $ellipse, $multi] as $selector) {
    $left = $rotation->rotate($target($selector), 112, 72, -90);
    check($rotation->rotate($left, 72, 112, 90) == $target($selector), 'Opposite turns restore all shapes, including polygon holes.');
}
try {
    $rotation->rotate(['selector' => ['type' => 'UNKNOWN']], 112, 72);
    throw new RuntimeException('Unsupported shapes must not silently remain unrotated.');
} catch (FileException) {
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [
    ObjectRecord::class, Project::class, ReportSeries::class, Report::class, ReportImage::class, DamageCase::class, Annotation::class,
]));
$object = new ObjectRecord('ROTATE-TEST', 'Fixture');
$series = new ReportSeries($object);
$report = new Report($series);
$photo = (new ReportImage($report, '/uploads/reports/images/photo.png'))->setThumbnailPath('/uploads/reports/images/photo-thumb.jpg');
$otherPhoto = new ReportImage($report, '/uploads/reports/images/other.png');
$damage = new DamageCase($report, 'fixture-case', 'Scratch');
$main = (new Annotation($report, $damage, 'main', 'main'))->setTarget($target($polygon));
$other = (new Annotation($report, $damage, 'image-other', 'other'))->setReportImage($otherPhoto)->setTarget($target($ellipse));
$annotations = [];
foreach ([$polygon, $ellipse, $multi, $polygon] as $index => $selector) {
    $value = $target($selector);
    $value['annotation'] = 'fixture-' . $index;
    if ($index === 3) unset($value['sourceDimensions']); // Older drawings have no stored dimensions.
    $annotation = (new Annotation($report, $damage, 'image-photo', 'fixture-' . $index))
        ->setReportImage($photo)->setTarget($value)->setBodies([['purpose' => 'commenting', 'value' => 'Keep me']]);
    if ($index === 3) $annotation->delete();
    $annotations[] = $annotation;
}
foreach ([$object, $series, $report, $photo, $otherPhoto, $damage, $main, $other, ...$annotations] as $entity) $manager->persist($entity);
$manager->flush();
$initialTargets = array_map(static fn (Annotation $annotation): array => $annotation->getTarget(), $annotations);

$container = new Container();
$container->set('security.authorization_checker', new class implements AuthorizationCheckerInterface {
    public function isGranted(mixed $attribute, mixed $subject = null): bool { return false; }
});
$container->set('security.csrf.token_manager', new class implements CsrfTokenManagerInterface {
    public function getToken(string $tokenId): CsrfToken { return new CsrfToken($tokenId, 'fixture'); }
    public function refreshToken(string $tokenId): CsrfToken { return $this->getToken($tokenId); }
    public function removeToken(string $tokenId): ?string { return null; }
    public function isTokenValid(CsrfToken $token): bool { return $token->getValue() === 'fixture'; }
});
$routes = new RouteCollection();
$routes->add('reports_edit', new Route('/{_locale}/reports/{id}/edit'));
$container->set('router', new UrlGenerator($routes, new RequestContext()));
$request = Request::create('/fixture/rotate', 'POST', ['_token' => 'fixture']);
$request->setLocale('nl');
$request->setSession(new Session(new MockArraySessionStorage()));
$stack = new RequestStack();
$stack->push($request);
$container->set('request_stack', $stack);
$reflection = new ReflectionClass(ReportController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('entityManager')->setValue($controller, $manager);
$controller->setContainer($container);
$directory = __DIR__ . '/image-rotate-fixture-' . bin2hex(random_bytes(6));
mkdir($directory);
$storage = new ReportImageStorage($directory, $directory);
$reflection->getProperty('reportImageStorage')->setValue($controller, $storage);

try {
    $pixels = imagecreatetruecolor(112, 72);
    imagefilledrectangle($pixels, 0, 0, 55, 35, 0xff0000);
    imagefilledrectangle($pixels, 56, 0, 111, 35, 0x00ff00);
    imagefilledrectangle($pixels, 0, 36, 55, 71, 0x0000ff);
    imagefilledrectangle($pixels, 56, 36, 111, 71, 0xffff00);
    $originalPixels = [];
    for ($y = 0; $y < 72; ++$y) {
        for ($x = 0; $x < 112; ++$x) $originalPixels[] = imagecolorat($pixels, $x, $y);
    }
    imagepng($pixels, $directory . '/photo.png');
    imagejpeg($pixels, $directory . '/photo-thumb.jpg');
    imagedestroy($pixels);

    $request->request->set('direction', 'invalid');
    try {
        $controller->rotateImage($report->getId(), $photo->getId(), $request, $rotation);
        throw new RuntimeException('Invalid rotation directions must be rejected.');
    } catch (Symfony\Component\HttpKernel\Exception\BadRequestHttpException) {
        check($storage->exists($photo->getPath()) && count(glob($directory . '/*')) === 2, 'Reject invalid directions before changing files.');
    }
    foreach (['right', 'right', 'right', 'right', 'left', 'left', 'left', 'left', 'right', 'left'] as $direction) {
        $request->request->set('direction', $direction);
        $oldPath = $photo->getPath();
        $oldThumb = $photo->getThumbnailPath();
        $before = imagecreatefrompng($directory . '/' . basename($oldPath));
        $response = $controller->rotateImage($report->getId(), $photo->getId(), $request, $rotation);
        check(str_contains($response->headers->get('Location'), 'tab=damage'), 'Return to the image editor.');
        $manager->refresh($photo);
        foreach ($annotations as $annotation) $manager->refresh($annotation);
        check($photo->getPath() !== $oldPath && $photo->getThumbnailPath() !== $oldThumb, 'Change both URLs to avoid cached images.');
        check(!$storage->exists($oldPath) && !$storage->exists($oldThumb), 'Remove replaced files after a successful commit.');
        $after = imagecreatefrompng($directory . '/' . basename($photo->getPath()));
        check(imagesx($after) === imagesy($before) && imagesy($after) === imagesx($before), 'Swap actual pixel dimensions.');
        foreach ([[5, 5], [60, 5], [5, 60], [60, 60]] as [$x, $y]) {
            [$newX, $newY] = $direction === 'right' ? [imagesy($before) - 1 - $y, $x] : [$y, imagesx($before) - 1 - $x];
            check(imagecolorat($after, $newX, $newY) === imagecolorat($before, $x, $y), 'Pixels must rotate in the requested direction with the coordinates.');
        }
        $dimensions = ['width' => imagesx($after), 'height' => imagesy($after)];
        foreach ($annotations as $index => $annotation) {
            check($annotation->getTarget()['sourceDimensions'] == $dimensions, 'Persist rotated dimensions for viewer and PDF output, including legacy annotations.');
            check($annotation->isDeleted() === ($index === 3), 'Keep annotation history deletion flags.');
            check($annotation->getBodies() === [['purpose' => 'commenting', 'value' => 'Keep me']], 'Preserve comments.');
        }
        $thumbSize = getimagesize($directory . '/' . basename($photo->getThumbnailPath()));
        check([$thumbSize[0], $thumbSize[1]] === array_values($dimensions), 'Regenerate the thumbnail in the same orientation.');
        imagedestroy($before);
        imagedestroy($after);
    }
    foreach ($annotations as $index => $annotation) {
        check($annotation->getTarget() == ($initialTargets[$index] + ['sourceDimensions' => ['width' => 112, 'height' => 72]]), 'Full turns and opposite turns must restore every coordinate, bound and source dimension.');
    }
    $manager->refresh($main);
    $manager->refresh($other);
    check($main->getTarget() === $target($polygon) && $other->getTarget() === $target($ellipse), 'Never rotate other photos or the main image annotations.');
    $pixels = imagecreatefrompng($directory . '/' . basename($photo->getPath()));
    for ($y = 0; $y < 72; ++$y) {
        for ($x = 0; $x < 112; ++$x) check(imagecolorat($pixels, $x, $y) === $originalPixels[$y * 112 + $x], 'Full and opposite PNG rotations restore every original pixel.');
    }
    imagedestroy($pixels);

    // Force a database write failure after rendering the new file: the old image/targets must survive.
    $oldPath = $photo->getPath();
    $oldHash = hash_file('sha256', $directory . '/' . basename($oldPath));
    $oldTargets = $manager->getConnection()->fetchAllAssociative('SELECT id, target FROM annotations ORDER BY id');
    $table = $manager->getClassMetadata(ReportImage::class)->getTableName();
    $manager->getConnection()->executeStatement("CREATE TRIGGER reject_rotation BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT, 'Fixture database failure'); END");
    try {
        $controller->rotateImage($report->getId(), $photo->getId(), $request, $rotation);
        throw new RuntimeException('The forced database failure must be raised.');
    } catch (Doctrine\DBAL\Exception $exception) {
        check(str_contains($exception->getMessage(), 'Fixture database failure'), 'Exercise a real transaction failure.');
    }
    check($manager->getConnection()->fetchOne("SELECT path FROM $table WHERE id = ?", [$photo->getId()]) === $oldPath, 'Rollback the image URL.');
    check($manager->getConnection()->fetchAllAssociative('SELECT id, target FROM annotations ORDER BY id') === $oldTargets, 'Rollback all annotation coordinates.');
    check($storage->exists($oldPath) && hash_file('sha256', $directory . '/' . basename($oldPath)) === $oldHash, 'Preserve the original image on failure.');
    check(count(glob($directory . '/*')) === 2, 'Remove failed replacement files without leaving orphans.');
    echo "PASS: left/right pixels and annotations, ellipses, polygon holes, full and opposite turns, thumbnails, history, other images and database rollback.\n";
} finally {
    // Only files created in this unique fixture directory; never traverse or delete recursively.
    foreach (glob($directory . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
}
