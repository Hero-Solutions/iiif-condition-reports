<?php

declare(strict_types=1);

// Isolated SQLite, XML fixtures and Symfony Forms. No kernel, environment or HTTP client.
use App\Command\HarvestDatahubObjectsCommand;
use App\Datahub\LidoMeasurements;
use App\Controller\ObjectController;
use App\Entity\{IIIFManifest, ObjectManifest, ObjectRecord, ObjectLogEntry, Project, ProjectObject, Report, ReportSeries, User};
use App\Form\ObjectRecordType;
use App\Service\{ObjectChangeLogger, ObjectImageStorage, ObjectThumbnailProvider, ReportAuthorProvider};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

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
    if (!$condition) throw new RuntimeException($message);
}

function callPrivate(object $object, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
}

function prepare(HarvestDatahubObjectsCommand $command, array $fields, string $recordId): ?array
{
    $xml = '<lido xmlns="http://www.lido-schema.org">';
    $definitions = [];
    foreach ($fields as $key => $value) {
        $xml .= '<' . $key . '>' . htmlspecialchars($value, ENT_XML1) . '</' . $key . '>';
        $definitions[$key] = ['xpath' => $key];
    }
    $data = new SimpleXMLElement($xml . '</lido>');
    $data->registerXPathNamespace('lido', 'http://www.lido-schema.org');

    return callPrivate($command, 'prepareData', $data, $recordId, [
        'namespace' => 'lido', 'language' => 'nl', 'data_definition' => $definitions,
        'placeholder_images' => ['https://images.invalid/placeholder'],
    ]);
}

function sync(EntityManager $manager, array $fields, string $recordId = 'record-1'): ObjectRecord
{
    $command = new HarvestDatahubObjectsCommand($manager, new ParameterBag(), new ObjectChangeLogger($manager), new LidoMeasurements(), new \App\Service\MainImageHistory($manager, new \App\Service\ObjectImageStorage(__DIR__, dirname(__DIR__))));
    $prepared = prepare($command, $fields, $recordId);
    check($prepared !== null, 'Fixture must have an inventory number.');
    callPrivate($command, 'preloadBatch', [$prepared]);
    callPrivate($command, 'syncPreparedRecord', $prepared);
    $manager->flush();

    return $manager->getRepository(ObjectRecord::class)->findOneBy(['inventoryNumber' => trim($fields['id'])]);
}

$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [
    ObjectRecord::class, IIIFManifest::class, ObjectManifest::class, ObjectLogEntry::class,
    Project::class, ProjectObject::class, ReportSeries::class, Report::class,
    \App\Entity\Annotation::class, \App\Entity\DamageCase::class, \App\Entity\ReportImage::class,
    \App\Entity\ObjectImageVersion::class, \App\Entity\ReportMainImage::class,
]));
$translator = new Translator('nl');
$forms = Forms::createFormFactoryBuilder()
    ->addType(new ObjectRecordType($translator))
    ->addExtension(new ValidatorExtension(Validation::createValidator()))
    ->getFormFactory();

$object = new ObjectRecord();
$form = $forms->create(ObjectRecordType::class, $object);
foreach ($form as $field) check(!$field->isDisabled(), 'Manual fields must be editable.');
$form->submit([
    'inventoryNumber' => '0001', 'title' => 'Manual title', 'creator' => 'Manual creator',
    'objectType' => 'other', 'customObjectType' => 'Manual type',
    'publisherChoice' => '__custom__', 'publisher' => 'Manual owner',
    'currentLocation' => 'Manual location', 'description' => 'Manual description',
]);
check($form->isValid(), 'Manual object creation must remain valid: ' . $form->getErrors(true));
$object->setImageUrl('/uploads/objects/fixture.jpg')->setThumbnailUrl('/uploads/objects/thumb.jpg');
$project = new Project('Fixture project');
$projectObject = new ProjectObject($project, $object);
$series = new ReportSeries($object, '', $project);
$report = new Report($series);
foreach ([$object, $project, $projectObject, $series, $report] as $entity) $manager->persist($entity);
$manager->flush();
$objectId = $object->getId();

$synced = sync($manager, ['id' => ' 0001 ', 'creator_nl' => 'Datahub creator']);
check($synced->getId() === $objectId, 'Sync must match the manual object by trimmed inventory number.');
check($synced->getTitle() === 'Manual title' && $synced->getCreator() === 'Datahub creator', 'Only supplied values replace manual entries.');
check($synced->getPublisher() === 'Manual owner' && $synced->getCustomObjectType() === 'Manual type', 'Missing owner and type must survive.');
check($synced->getImageUrl() === '/uploads/objects/fixture.jpg', 'Keep a manual image when Datahub has none.');
check($report->getObjectRecord()->getId() === $objectId && $projectObject->getObjectRecord()->getId() === $objectId, 'Keep project/report associations.');
$form = $forms->create(ObjectRecordType::class, $synced);
check($form->get('creator')->isDisabled() && $form->get('inventoryNumber')->isDisabled(), 'Supplied values and match key must be locked.');
check(!$form->get('title')->isDisabled() && !$form->get('publisherChoice')->isDisabled(), 'Missing source fields must remain editable.');
$form->submit(['title' => 'Manual supplement', 'creator' => 'Tampered creator', 'inventoryNumber' => 'changed'], false);
check($form->isValid() && $synced->getTitle() === 'Manual supplement', 'Allow manual supplements.');
check($synced->getCreator() === 'Datahub creator' && $synced->getInventoryNumber() === '0001', 'Ignore tampering with imported fields.');
sync($manager, ['id' => '0001', 'title_nl' => ' ', 'creator_nl' => 'Datahub creator']);
check($synced->getTitle() === 'Manual supplement', 'A blank daily source value must not erase a supplement.');

$allFields = [
    'id' => '0001', 'title_en' => 'Imported title', 'creator_en' => 'Imported creator',
    'publisher' => 'Imported owner', 'object_type_nl' => 'schilderij',
    'description_en' => 'Imported description', 'current_location' => '0',
    'iiif_manifest_url' => 'https://images.invalid/manifest',
];
sync($manager, $allFields, 'changed-record-id');
check($synced->getId() === $objectId && $synced->getExternalId() === 'changed-record-id', 'Changing external ID must not create a duplicate.');
check($synced->getTitle() === 'Imported title' && $synced->getCreator() === 'Imported creator', 'Source values must override local_changes.');
check($synced->getPublisher() === 'Imported owner' && $synced->getDescription() === 'Imported description', 'Imported owner and description must replace manual values.');
check($synced->getObjectType() === 'painting' && $synced->getCustomObjectType() === null, 'Imported type replaces manual type.');
check($synced->getCurrentLocation() === '0' && $synced->isDatahubField('currentLocation'), 'Zero is a supplied value.');
check($synced->getImageUrl() === null && $synced->getThumbnailUrl() === null, 'An imported image must override the local image.');
check($synced->getSyncStatus() === ObjectRecord::SYNC_SYNCED, 'Completed sync must update status.');
$link = $manager->getRepository(ObjectManifest::class)->findOneBy(['objectRecord' => $synced]);
check($link?->getManifest()->getManifestId() === $allFields['iiif_manifest_url'], 'Link the imported manifest.');
$linkId = $link->getId();
$form = $forms->create(ObjectRecordType::class, $synced, ['manifest_url' => $allFields['iiif_manifest_url']]);
foreach ($form as $field) check($field->isDisabled(), 'All supplied fields must be disabled: ' . $field->getName());
$before = serialize($synced);
$form->submit(array_fill_keys(array_keys($form->all()), 'tampered'));
check($form->isValid() && serialize($synced) === $before, 'Even a forged POST cannot modify source data.');

// Saving other object details must not fetch, delete or replace the protected image.
$storage = new class extends ObjectImageStorage {
    public function __construct() {}
    public function store(UploadedFile $image): array { throw new RuntimeException('Unexpected upload.'); }
    public function storeExternal(string $url): array { throw new RuntimeException('Unexpected download.'); }
    public function remove(?string $url): void { throw new RuntimeException('Unexpected image deletion.'); }
    public function isStoredImage(?string $url): bool { throw new RuntimeException('Unexpected file access.'); }
};
$logger = new ObjectChangeLogger($manager);
$controller = new ObjectController($manager, new ObjectThumbnailProvider($manager), $storage, $translator, new ReportAuthorProvider($manager), $logger, true, new \App\Service\MainImageHistory($manager, new \App\Service\ObjectImageStorage(__DIR__, dirname(__DIR__))));
$tokenStorage = new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage();
$tokenStorage->setToken(new Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken((new User())->setFullName('Fixture user'), 'test', ['ROLE_USER']));
$container = new Symfony\Component\DependencyInjection\Container();
$container->set('security.token_storage', $tokenStorage);
$controller->setContainer($container);
check(callPrivate($controller, 'saveObject', $synced, $form, $logger->snapshot($synced, $allFields['iiif_manifest_url'])), 'Saving a protected object must succeed.');
check($manager->find(ObjectManifest::class, $linkId)?->getId() === $linkId, 'Saving must retain the imported manifest.');

sync($manager, ['id' => '0001']);
check(!$synced->isDatahubField('title') && $synced->getTitle() === 'Imported title', 'A removed source value unlocks the field without erasing its value.');
$form = $forms->create(ObjectRecordType::class, $synced);
$form->submit(['title' => 'New supplement'], false);
check($form->isValid() && $synced->getTitle() === 'New supplement', 'A newly missing field can be edited.');
sync($manager, $allFields);
check($synced->getTitle() === 'Imported title', 'When the source value returns it replaces the supplement.');
check(sync($manager, ['id' => '1'], 'record-1')->getId() !== $objectId, 'Never match by external ID or drop leading zeroes.');
$new = sync($manager, ['id' => 'NEW', 'object_type_nl' => 'Keramiek']);
check($new->getTitle() === 'NEW' && !$new->isDatahubField('title'), 'Fallback display title must remain editable.');
check($new->getObjectType() === 'other' && $new->getCustomObjectType() === 'Keramiek' && $new->isDatahubField('objectType'), 'Keep unknown imported types as a locked custom value.');

// Manual manifests remain until an actual Datahub image arrives (not a placeholder).
$imageObject = new ObjectRecord('IMAGE', 'Image fixture');
$manifest = (new IIIFManifest())->setManifestId('https://images.invalid/manual')->setSource(IIIFManifest::SOURCE_MANUAL);
$manualLink = new ObjectManifest($imageObject, $manifest);
foreach ([$imageObject, $manifest, $manualLink] as $entity) $manager->persist($entity);
$manager->flush();
sync($manager, ['id' => 'IMAGE', 'iiif_image_url' => 'https://images.invalid/placeholder', 'iiif_manifest_url' => 'https://images.invalid/placeholder-manifest']);
check(!$imageObject->isDatahubField('image') && $manager->contains($manualLink), 'A placeholder must not lock or replace a manual image.');
sync($manager, ['id' => 'IMAGE', 'iiif_image_url' => 'https://images.invalid/public@fixture']);
check($imageObject->isDatahubField('image') && !$manager->contains($manualLink), 'A Datahub image without manifest must replace a manual manifest.');

$command = new HarvestDatahubObjectsCommand($manager, new ParameterBag(), $logger, new LidoMeasurements(), new \App\Service\MainImageHistory($manager, new \App\Service\ObjectImageStorage(__DIR__, dirname(__DIR__))));
check(prepare($command, ['fallback_id' => 'LOCAL-ID', 'title_nl' => 'No inventory number'], '0001') === null, 'Neither local ID nor record ID is an inventory number.');
check(prepare($command, ['id' => ' '], '0001') === null, 'Skip empty inventory numbers.');
check(prepare($command, ['id' => str_repeat('A', 101)], 'record') === null, 'Do not truncate inventory numbers and create false matches.');
$datahubConfig = Yaml::parse(file_get_contents($root . '/config/packages/datahub.yaml'));
check(!isset($datahubConfig['parameters']['datahub']['data_definition']['fallback_id']), 'Only the inventory number is configured as match key.');
foreach (['nl', 'en'] as $locale) {
    $messages = Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml'));
    check(isset($messages['objects']['datahub_readonly_help']), 'Translate the source explanation.');
}

$twig = new Twig\Environment(new Twig\Loader\ArrayLoader());
$twig->addExtension(new Symfony\Bridge\Twig\Extension\FormExtension());
$twig->addExtension(new Symfony\Bridge\Twig\Extension\TranslationExtension($translator));
foreach (['path', 'is_granted', 'csrf_token'] as $name) $twig->addFunction(new Twig\TwigFunction($name, static fn () => ''));
$twig->addFilter(new Twig\TwigFilter('report_title', static fn () => ''));
$twig->parse($twig->tokenize(new Twig\Source(file_get_contents($root . '/templates/objects/form.html.twig'), 'object_form')));

echo "PASS: inventory matching, manual creation, field-level sync, missing values, forged submissions, image precedence and associations.\n";
