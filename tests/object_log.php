<?php

declare(strict_types=1);

// SQLite in memory, fake users and fake image storage. No application bootstrap or network.
use App\Controller\ObjectController;
use App\Entity\{Actor, IIIFManifest, ObjectLogEntry, ObjectManifest, ObjectRecord, User};
use App\Form\ObjectRecordType;
use App\Service\{ObjectChangeLogger, ObjectImageStorage, ObjectThumbnailProvider, ReportAuthorProvider};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\{Translator, Loader\ArrayLoader};
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

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

function callPrivate(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [Actor::class, ObjectRecord::class, ObjectLogEntry::class, ObjectManifest::class, IIIFManifest::class, User::class]));
$logger = new ObjectChangeLogger($manager);
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
$forms = Forms::createFormFactoryBuilder()->addType(new ObjectRecordType($translator))->addExtension(new HttpFoundationExtension())->addExtension(new ValidatorExtension(Validation::createValidator()))->getFormFactory();
$storage = new class extends ObjectImageStorage {
    public bool $fail = false;
    public function __construct() {}
    public function isStoredImage(?string $url): bool { return $url !== null && str_starts_with($url, '/uploads/'); }
    public function exists(?string $url): bool { return $this->isStoredImage($url); }
    public function remove(?string $url): void {}
    public function store(UploadedFile $image): array { throw new RuntimeException('No file uploads in this fixture.'); }
    public function storeExternal(string $url): array
    {
        if ($this->fail) throw new FileException('Fixture failure.');
        return ['image_url' => '/uploads/fixture.jpg', 'thumbnail_url' => '/uploads/thumb.jpg'];
    }
};
$controller = new ObjectController($manager, new ObjectThumbnailProvider($manager), $storage, $translator, new ReportAuthorProvider($manager), $logger, true, new \App\Service\MainImageHistory($manager));
$disabledController = new ObjectController($manager, new ObjectThumbnailProvider($manager), $storage, $translator, new ReportAuthorProvider($manager), $logger, false, new \App\Service\MainImageHistory($manager));
foreach (['GET', 'POST'] as $method) {
    try {
        $disabledController->new(Request::create('/nl/objects/new', $method));
        throw new RuntimeException('Disabled manual creation must reject ' . $method . '.');
    } catch (Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        check($manager->getRepository(ObjectRecord::class)->count([]) === 0, 'Denied requests must not create objects.');
    }
}
$user = (new User())->setEmail('fixture@example.invalid')->setFullName('Fixture editor');
$manager->persist($user);
$manager->flush();
$tokenStorage = new TokenStorage();
$tokenStorage->setToken(new UsernamePasswordToken($user, 'test', ['ROLE_USER']));
$container = new Container();
$container->set('security.token_storage', $tokenStorage);
$controller->setContainer($container);
$repository = $manager->getRepository(ObjectLogEntry::class);

$object = new ObjectRecord();
$form = $forms->create(ObjectRecordType::class, $object);
$form->submit(['inventoryNumber' => 'LOG-1', 'title' => 'Original', 'objectType' => 'painting', 'description' => "Line one\n<script>unsafe</script>"]);
check($form->isValid(), 'Creation form must be valid.');
check(callPrivate($controller, 'saveObject', $object, $form, []), 'Save the new object.');
$created = $repository->findOneBy(['objectRecord' => $object]);
check($created?->getType() === ObjectLogEntry::MANUAL_CREATE, 'Log manual creation.');
check($created->getUserId() === $user->getId() && $created->getUserName() === 'Fixture editor', 'Capture the authenticated editor.');
check($created->getChanges()['inventoryNumber'] === ['old' => null, 'new' => 'LOG-1'], 'Capture newly entered values.');

$before = $logger->snapshot($object, '');
$form = $forms->create(ObjectRecordType::class, $object);
$form->submit(['title' => 'Changed', 'description' => '', 'externalImageUrl' => 'https://images.invalid/photo.jpg'], false);
check($form->isValid() && callPrivate($controller, 'saveObject', $object, $form, $before), 'Save manual changes with a fake image.');
$updated = $repository->findOneBy(['objectRecord' => $object], ['id' => 'DESC']);
check($updated->getChanges()['title'] === ['old' => 'Original', 'new' => 'Changed'], 'Capture before/after title values.');
check($updated->getChanges()['description']['new'] === null, 'Capture clearing a field.');
check($updated->getChanges()['imageUrl']['new'] === '/uploads/fixture.jpg', 'Log the stored image.');
check(!isset($updated->getChanges()['inventoryNumber']), 'Do not list unchanged fields.');

$before = $logger->snapshot($object, '');
$form = $forms->create(ObjectRecordType::class, $object, ['external_image_url' => 'https://images.invalid/photo.jpg']);
$form->submit([], false);
callPrivate($controller, 'saveObject', $object, $form, $before);
check($repository->count(['objectRecord' => $object]) === 2, 'A manual save without changes must not create noise.');

$before = $logger->snapshot($object, '');
$storage->fail = true;
$form = $forms->create(ObjectRecordType::class, $object);
$form->submit(['externalImageUrl' => 'https://images.invalid/failure.jpg'], false);
check(!callPrivate($controller, 'saveObject', $object, $form, $before), 'Image failure must reject the save.');
check($repository->count(['objectRecord' => $object]) === 2, 'A failed save must not be logged.');

$before = $logger->snapshot($object, '');
$object->applyDatahubSync('LOG-1', 'record-1', ['title_nl' => 'Imported', 'technique_nl' => 'Olieverf', '_datahub_synced_at' => 'first']);
$logger->record($object, $before, ObjectLogEntry::DATAHUB_SYNC, manifestUrl: '', createdAt: new DateTimeImmutable('2026-10-13 12:00:00'));
$manager->flush();
$sync = $repository->findOneBy(['objectRecord' => $object], ['id' => 'DESC']);
check($sync->getUserName() === null && $sync->getType() === ObjectLogEntry::DATAHUB_SYNC, 'A sync must never be attributed to a user.');
check($sync->getCreatedAt()->format('Y-m-d H:i:s') === '2026-10-13 12:00:00', 'Keep the synchronization date.');
check($sync->getChanges()['title'] === ['old' => 'Changed', 'new' => 'Imported'], 'Log values overwritten by Datahub.');
check($sync->getChanges()['datahub.technique_nl']['new'] === 'Olieverf', 'Also keep changes to additional source fields.');
check(!isset($sync->getChanges()['datahub._datahub_synced_at']), 'Do not turn internal timestamps into field changes.');

$before = $logger->snapshot($object, '');
$object->setSourceData($object->getSourceData() + ['_run' => 'second']);
$logger->record($object, $before, ObjectLogEntry::DATAHUB_SYNC, manifestUrl: '');
$manager->flush();
$unchanged = $repository->findOneBy(['objectRecord' => $object], ['id' => 'DESC']);
check($unchanged->getChanges() === [], 'Still record an unchanged sync, without fabricated differences.');

$user->setFullName('Renamed editor');
$manager->flush();
$manager->remove($user);
$manager->flush();
$manager->refresh($created);
check($created->getUserName() === 'Fixture editor', 'Renaming/deleting a user must not rewrite the historical attribution.');
check($created->getChanges()['title']['new'] === 'Original', 'Later edits must not rewrite earlier log values.');

$twig = new Twig\Environment(new Twig\Loader\ArrayLoader([
    'base.html.twig' => '{% block body %}{% endblock %}',
    'objects/form.html.twig' => file_get_contents($root . '/templates/objects/form.html.twig'),
    'objects/index.html.twig' => file_get_contents($root . '/templates/objects/index.html.twig'),
    'objects/_log.html.twig' => file_get_contents($root . '/templates/objects/_log.html.twig'),
    'form_div_layout.html.twig' => file_get_contents($root . '/vendor/symfony/twig-bridge/Resources/views/Form/form_div_layout.html.twig'),
]), ['strict_variables' => true]);
$twig->addExtension(new Symfony\Bridge\Twig\Extension\TranslationExtension($translator));
$twig->addExtension(new Symfony\Bridge\Twig\Extension\FormExtension());
$twig->addRuntimeLoader(new Twig\RuntimeLoader\FactoryRuntimeLoader([
    Symfony\Component\Form\FormRenderer::class => static fn () => new Symfony\Component\Form\FormRenderer(new Symfony\Bridge\Twig\Form\TwigRendererEngine(['form_div_layout.html.twig'], $twig)),
]));
$twig->addFunction(new Twig\TwigFunction('path', static fn () => '/fixture'));
$twig->addFunction(new Twig\TwigFunction('is_granted', static fn () => false));
$twig->addFunction(new Twig\TwigFunction('csrf_token', static fn () => 'fixture'));
$twig->addFilter(new Twig\TwigFilter('report_title', static fn () => 'Fixture report'));
$twig->addGlobal('app', ['request' => ['locale' => 'nl']]);
foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    $html = $twig->render('objects/_log.html.twig', [
        'log_entries' => [$unchanged, $sync, $updated, $created], 'log_page' => 2, 'log_has_more' => true,
        'object' => $object, 'app' => ['request' => ['locale' => $locale]],
    ]);
    check(str_contains($html, '<h2>Log</h2>'), 'Use the requested heading.');
    check(str_contains($html, $translator->trans('object_log.no_changes')), 'Explain unchanged syncs.');
    check(!str_contains($html, '<script>unsafe</script>') && str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;'), 'Escape log values.');
    check(str_contains($html, $translator->trans('object_log.older')) && str_contains($html, $translator->trans('object_log.newer')), 'Keep older log entries accessible.');

    $html = $twig->render('objects/form.html.twig', [
        'form' => $forms->create(ObjectRecordType::class, $object)->createView(),
        'object' => $object, 'is_new' => false, 'thumbnail_url' => null,
        'reports' => [], 'reports_count' => 0, 'report_author_names' => [],
        'log_entries' => [$unchanged, $sync, $updated, $created], 'log_page' => 1, 'log_has_more' => false,
        'app' => ['request' => ['locale' => $locale]],
    ]);
    check(str_contains($html, 'id="object-log"'), 'Render the log in the actual object form.');
    check(!str_contains($html, 'class="object-meta"') && !str_contains($html, '<h2>' . $translator->trans('objects.source_data') . '</h2>'), 'Replace the old metadata/source sections.');
}

// The feature flag hides creation links and blocks the route, without changing object editing.
$container->set('form.factory', $forms);
$container->set('twig', $twig);
check($controller->new(Request::create('/nl/objects/new'))->getStatusCode() === 200, 'Enabled manual creation must still render its form.');
foreach ([true, false] as $enabled) {
    $html = $twig->render('objects/index.html.twig', [
        'manual_object_creation_enabled' => $enabled, 'objects' => [], 'thumbnail_urls' => [],
        'inventory_number' => '', 'match' => 'exact',
    ]);
    check(str_contains($html, $translator->trans('objects.new')) === $enabled, 'Creation link must follow the setting.');
    check(str_contains($html, $translator->trans($enabled ? 'objects.empty_text' : 'objects.empty_text_datahub')), 'Empty-list help must match the setting.');
}

// Log and object changes must commit or roll back together.
$connection = $manager->getConnection();
$count = (int) $connection->fetchOne('SELECT COUNT(*) FROM object_log_entries');
$duplicate = new ObjectRecord('LOG-1', 'Duplicate');
$manager->persist($duplicate);
$logger->record($duplicate, [], ObjectLogEntry::DATAHUB_SYNC);
try {
    $manager->flush();
    throw new RuntimeException('Expected unique inventory number failure.');
} catch (Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
    check((int) $connection->fetchOne('SELECT COUNT(*) FROM object_log_entries') === $count, 'Failed database writes must not leave audit entries.');
}

echo "PASS: creation flag (GET/POST and links), manual changes, actor history, clears/images, no-op and failed saves, Datahub values, escaping, pagination and rollback.\n";
