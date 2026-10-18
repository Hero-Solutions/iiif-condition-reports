<?php

declare(strict_types=1);

// SQLite in memory, temporary fixture files in tests/, fake auth/CSRF. No app bootstrap or network.
use App\Controller\ReportController;
use App\Entity\{ObjectRecord, Project, Report, ReportDocument, ReportSeries};
use App\Service\ReportDocumentStorage;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\{Request, RequestStack, Session\Session, Session\Storage\MockArraySessionStorage};
use Symfony\Component\HttpFoundation\File\{File, UploadedFile};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, NotFoundHttpException};
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

// Windows sandbox directory-writability probes can fail despite successful fixture writes.
// Simulate only the HTTP upload move; MIME validation and document persistence remain real.
final class FixtureUpload extends UploadedFile
{
    public function move(string $directory, ?string $name = null): File
    {
        $target = $directory . '/' . $name;
        check(rename($this->getPathname(), $target), 'Move the isolated upload fixture.');
        return new File($target);
    }
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [ObjectRecord::class, Project::class, ReportSeries::class, Report::class, ReportDocument::class]));
$object = new ObjectRecord('DOCUMENT-TEST', 'Fixture');
$series = new ReportSeries($object);
$report = new Report($series);
$otherReport = new Report($series);
foreach ([$object, $series, $report, $otherReport] as $entity) $manager->persist($entity);
$manager->flush();
$reportId = $report->getId();

$authorization = new class implements AuthorizationCheckerInterface {
    public bool $readOnly = false;
    public function isGranted(mixed $attribute, mixed $subject = null): bool { return $this->readOnly; }
};
$csrf = new class implements CsrfTokenManagerInterface {
    public function getToken(string $tokenId): CsrfToken { return new CsrfToken($tokenId, 'fixture'); }
    public function refreshToken(string $tokenId): CsrfToken { return $this->getToken($tokenId); }
    public function removeToken(string $tokenId): ?string { return null; }
    public function isTokenValid(CsrfToken $token): bool { return $token->getValue() === 'fixture'; }
};
$routes = new RouteCollection();
$routes->add('reports_edit', new Route('/{_locale}/reports/{id}/edit'));
$stack = new RequestStack();
$container = new Container();
$container->set('security.authorization_checker', $authorization);
$container->set('security.csrf.token_manager', $csrf);
$container->set('router', new UrlGenerator($routes, new RequestContext()));
$container->set('request_stack', $stack);
$controllerClass = new ReflectionClass(ReportController::class);
$controller = $controllerClass->newInstanceWithoutConstructor();
$controllerClass->getProperty('entityManager')->setValue($controller, $manager);
$controller->setContainer($container);
$fixtureDirectory = __DIR__ . '/document-fixture-' . bin2hex(random_bytes(6));
mkdir($fixtureDirectory);
$controllerClass->getProperty('reportDocumentStorage')->setValue($controller, new ReportDocumentStorage($fixtureDirectory));
$request = static function (array $values, array $files = []) use ($stack): Request {
    $request = new Request([], $values + ['_token' => 'fixture'], [], [], $files);
    $request->setLocale('nl');
    $request->setSession(new Session(new MockArraySessionStorage()));
    if ($stack->getCurrentRequest()) $stack->pop();
    $stack->push($request);
    return $request;
};

try {
    foreach (['basis', 'recommendations'] as $section) {
        $file = $fixtureDirectory . '/upload-' . $section . '.txt';
        file_put_contents($file, 'Offline document fixture.');
        $response = $controller->uploadDocuments($report, $request([
            'section' => $section, 'category' => '__custom__', 'custom_category' => 'Custom kind',
        ], ['documents' => [new FixtureUpload($file, $section . '.txt', 'text/plain', null, true)]]));
        check(str_contains($response->headers->get('Location'), 'tab=' . $section), 'Return to the upload section.');
        $errors = $stack->getCurrentRequest()->getSession()->getFlashBag()->peek('error');
        check($errors === [], 'Fixture upload failed: ' . implode('; ', $errors));
    }
    $manager->clear();
    $report = $manager->find(Report::class, $reportId);
    $documents = $manager->getRepository(ReportDocument::class)->findBy(['report' => $report], ['id' => 'ASC']);
    check(array_map(static fn ($doc) => $doc->getSection(), $documents) === ['basis', 'recommendations'], 'Persist the section of each upload.');
    foreach ($documents as $document) {
        check($document->getCategory() === 'Custom kind', 'Section must not replace the custom document category.');
        check(is_file($fixtureDirectory . '/' . basename($document->getPath())), 'Store the uploaded file.');
    }

    foreach (['', 'materials', '../basis'] as $section) {
        try {
            $controller->uploadDocuments($report, $request(['section' => $section]));
            throw new RuntimeException('Invalid section accepted.');
        } catch (BadRequestHttpException) {}
    }
    foreach ([['_token' => 'invalid'], []] as $index => $values) {
        $authorization->readOnly = $index === 1;
        try {
            $controller->uploadDocuments($report, $request($values + ['section' => 'basis']));
            throw new RuntimeException('Forbidden upload accepted.');
        } catch (AccessDeniedException) {}
    }
    $authorization->readOnly = false;
    try {
        $controller->deleteDocument($otherReport->getId(), $documents[0]->getId(), $request([]));
        throw new RuntimeException('Deleted a document through another report.');
    } catch (NotFoundHttpException) {}

    $removedPath = $fixtureDirectory . '/' . basename($documents[1]->getPath());
    $response = $controller->deleteDocument($reportId, $documents[1]->getId(), $request([]));
    check(str_contains($response->headers->get('Location'), 'tab=recommendations'), 'Delete returns to the stored section.');
    check(!is_file($removedPath), 'Delete the associated file.');
    check($manager->getRepository(ReportDocument::class)->count(['report' => $report]) === 1, 'Other section documents remain.');

    $report->finalize(1);
    try {
        $controller->uploadDocuments($report, $request(['section' => 'basis']));
        throw new RuntimeException('Changed a finalized report.');
    } catch (AccessDeniedException) {}

    // Execute the new migration SQL only on an isolated, minimal old-schema fixture.
    require $root . '/migrations/Version20261013170000.php';
    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    $connection->executeStatement('CREATE TABLE report_documents (id INTEGER PRIMARY KEY, original_name VARCHAR(255))');
    $connection->insert('report_documents', ['id' => 1, 'original_name' => 'Existing.txt']);
    $migration = new DoctrineMigrations\Version20261013170000($connection, new Psr\Log\NullLogger());
    $migration->up(new Doctrine\DBAL\Schema\Schema());
    foreach ($migration->getSql() as $query) $connection->executeStatement($query->getStatement());
    check($connection->fetchOne('SELECT section FROM report_documents WHERE id = 1') === 'basis', 'Keep existing documents under Basis.');
    echo "PASS: document uploads, stored sections/categories, redirects, deletion, access checks and existing-document migration.\n";
} finally {
    // Only files created inside this uniquely named fixture directory.
    foreach (glob($fixtureDirectory . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($fixtureDirectory);
}
