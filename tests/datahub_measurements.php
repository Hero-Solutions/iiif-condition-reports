<?php

declare(strict_types=1);

// Isolated XML fixtures and SQLite in memory. No kernel, environment files or network.
use App\Command\HarvestDatahubObjectsCommand;
use App\Controller\ReportController;
use App\Datahub\LidoMeasurements;
use App\Entity\{Actor, IIIFManifest, ObjectLogEntry, ObjectManifest, ObjectRecord, OrganizationContact, Project, ProjectObject, Report, ReportActor, ReportSeries};
use App\Service\{ObjectChangeLogger, ProjectReportDefaults, ReportMeasurementDefaults};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\{Container, ParameterBag\ParameterBag};
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

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

function measure(string $type, string $value, string $unit): string
{
    return '<measurementsSet><measurementType>' . $type . '</measurementType><measurementValue>' . $value
        . '</measurementValue><measurementUnit>' . $unit . '</measurementUnit></measurementsSet>';
}

function group(string $extent, string $values, string $display = ''): string
{
    return '<objectMeasurementsSet><displayObjectMeasurements>' . $display . '</displayObjectMeasurements><objectMeasurements>'
        . $values . '<extentMeasurements>' . $extent . '</extentMeasurements></objectMeasurements></objectMeasurementsSet>';
}

function record(string $groups): SimpleXMLElement
{
    return new SimpleXMLElement('<lido xmlns="http://www.lido-schema.org"><workID>TEST-1</workID><descriptiveMetadata xml:lang="nl"><objectIdentificationWrap><objectMeasurementsWrap>'
        . $groups . '</objectMeasurementsWrap></objectIdentificationWrap></descriptiveMetadata></lido>', LIBXML_NONET);
}

$parser = new LidoMeasurements();
// The sample has one outer set per axis, repeating the same display description.
$fixture = record(
    group('Dagmaat', measure('Hoogte', '48', 'Millimeters'), '480 × 380 mm')
    . group('Dagmaat', measure('Breedte', '38', 'Millimeters'), '480 × 380 mm')
    . group('Volledig', measure('Hoogte', '227', 'mm'), '22,7 × 27,5 × 1,2 cm')
    . group('Volledig', measure('Breedte', '0.275', 'm'))
    . group('Volledig', measure('Diepte', '12', 'mm'))
    . group('Inclusief lijst', measure('Hoogte', '40', 'Centimeters') . measure('Breedte', '44.5', 'cm') . measure('Diepte', '0', 'cm') . measure('Gewicht', '7500', 'g'))
    . group('Onderdeel', measure('Hoogte', '440', 'cm'))
);
$measurements = $parser->extract($fixture, 'nl');
check($measurements['Dagmaat'] === ['height' => '4.8', 'width' => '3.8'], 'Structured source fields always win, even when implausible or contradicted.');
check($measurements['Volledig'] === ['height' => '22.7', 'width' => '27.5', 'depth' => '1.2'], 'Merge axes from separate sets and convert all to cm.');
check($measurements['Inclusief lijst']['depth'] === '0' && $measurements['Inclusief lijst']['weight'] === '7.5', 'Keep zeroes and convert weights to kg.');
check($parser->extract($fixture, 'en') === [], 'Do not mix metadata languages.');
check($parser->extract(record(''), 'nl') === [], 'Missing measurements are valid.');
check($parser->extract(record('<objectMeasurementsSet><displayObjectMeasurements>10 × 20 cm</displayObjectMeasurements></objectMeasurementsSet>'), 'nl') === ['' => ['height' => '10', 'width' => '20']], 'Display-only LIDO records do not require an objectMeasurements element.');

$values = $parser->extract(record(group('Volledig', measure('', '100', 'mm') . measure('', '2,5', 'dm') . measure('', '1', 'in'))), 'nl');
check($values['Volledig'] === ['height' => '10', 'width' => '25', 'depth' => '2.54'], 'Unlabelled structured triples use fixed H × W × D order.');
$values = $parser->extract(record(group('Dagmaat', measure('', '48', 'Millimeters'), '480 × 380 mm') . group('Dagmaat', measure('', '38', 'Millimeters'), '480 × 380 mm') . group('Dagmaat', measure('Gewicht', '1', 'kg'))), 'nl');
check($values['Dagmaat'] === ['height' => '4.8', 'width' => '3.8', 'weight' => '1'], 'Unlabelled axes in separate XML sets still take precedence over display text; weight does not shift axis positions.');
$values = $parser->extract(record(group('Volledig', measure('Breedte', '12', 'cm') . measure('Hoogte', '10', 'cm'))), 'nl');
check($values['Volledig'] === ['width' => '12', 'height' => '10'], 'Explicit axes override position.');
$values = $parser->extract(record(group('Volledig', '', '10 x 20 mm')), 'nl');
check($values['Volledig'] === ['height' => '1', 'width' => '2'], 'Plain display pair is a fallback with an explicit unit.');
$values = $parser->extract(record(group('Volledig', '', '1,2 × 2,3 × 0,5 m')), 'nl');
check($values['Volledig'] === ['height' => '120', 'width' => '230', 'depth' => '50'], 'Plain display triple supports decimal commas.');
$values = $parser->extract(record(group('Volledig', measure('Hoogte', '0', 'cm'), '10 × 20 cm') . group('Volledig', measure('Hoogte', '999', 'cm'))), 'nl');
check($values['Volledig'] === ['height' => '0', 'width' => '20'], 'First structured value wins consistently; display only fills missing axes.');
check($parser->extract(record(group('Volledig', measure('Hoogte', '10', 'unknown'), '10 × 20 cm')), 'nl')['Volledig'] === ['width' => '20'], 'Unknown units cannot replace structured source values with a display guess.');
foreach (['10 × 20', '10 cm', 'circa 10 × 20 cm', '10 × 20 cubits'] as $display) {
    check($parser->extract(record(group('Volledig', '', $display)), 'nl') === [], 'Do not guess missing units or interpret prose.');
}

$object = (new ObjectRecord('TEST-1', 'Fixture'))->setObjectType(ObjectRecord::TYPE_PAINTING);
$object->applyDatahubSync('TEST-1', 'fixture', ['measurements' => $measurements]);
$defaults = new ReportMeasurementDefaults();
$data = $defaults->for($object);
check($data['number_of_parts'] === '1', 'Import assumes exactly one part.');
check($data['height_full'] === '40' && $data['measurement_parts'][1]['height_with_frame'] === '40', 'With-frame dimensions fill the whole work and its single part.');
check($data['measurement_parts'][1]['height_without_frame'] === '22.7', 'Without-frame dimensions come from Volledig, never Dagmaat or Onderdeel.');
$object->setObjectType(ObjectRecord::TYPE_WORK_ON_PAPER);
$paper = $defaults->for($object);
check(!isset($paper['height_box']) && !isset($paper['height_full']) && $paper['measurement_parts'][1]['height_without_frame'] === '22.7', 'A frame must not be mapped to a storage box.');
check(!isset($paper['measurement_parts'][1]['depth_without_frame']), 'Only fill fields available for the object type.');
$object->setObjectType(ObjectRecord::TYPE_SCULPTURE);
check($defaults->for($object)['height_full'] === '22.7', 'Complete sculpture dimensions use Volledig.');
check($defaults->for(new ObjectRecord('MANUAL', 'Fixture')) === [], 'Manual objects keep existing behavior.');

// Actual harvest prepare/sync and draft creation, with an isolated database.
$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, __DIR__, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), [
    ObjectRecord::class, ObjectLogEntry::class, ObjectManifest::class, IIIFManifest::class,
    Project::class, ProjectObject::class, ReportSeries::class, Report::class, ReportActor::class, Actor::class, OrganizationContact::class,
]));
$harvest = new HarvestDatahubObjectsCommand($manager, new ParameterBag(), new ObjectChangeLogger($manager), $parser, new \App\Service\MainImageHistory($manager));
$fixture->registerXPathNamespace('lido', 'http://www.lido-schema.org');
$prepared = (new ReflectionMethod($harvest, 'prepareData'))->invoke($harvest, $fixture, 'fixture', [
    'namespace' => 'lido', 'language' => 'nl', 'data_definition' => ['id' => ['xpath' => 'workID']], 'placeholder_images' => [],
]);
check($prepared['source_data']['measurements'] === $measurements, 'The actual harvest must include the measurements.');
(new ReflectionMethod($harvest, 'preloadBatch'))->invoke($harvest, [$prepared]);
(new ReflectionMethod($harvest, 'syncPreparedRecord'))->invoke($harvest, $prepared);
$manager->flush();
$manager->clear();
$object = $manager->getRepository(ObjectRecord::class)->findOneBy(['inventoryNumber' => 'TEST-1']);
$object->setObjectType(ObjectRecord::TYPE_PAINTING);
check($object->getSourceData()['measurements'] === $measurements, 'Measurements survive persistence.');
check(isset($manager->getRepository(ObjectLogEntry::class)->findOneBy(['objectRecord' => $object])->getChanges()['datahub.measurements']), 'Imported dimensions are logged.');
$series = new ReportSeries($object);
$manager->persist($series);
$manager->flush();
$reflection = new ReflectionClass(ReportController::class);
$controller = $reflection->newInstanceWithoutConstructor();
foreach (['entityManager' => $manager, 'measurementDefaults' => $defaults, 'projectReportDefaults' => new ProjectReportDefaults()] as $name => $service) {
    $reflection->getProperty($name)->setValue($controller, $service);
}
$container = new Container();
$container->set('security.token_storage', new TokenStorage());
$controller->setContainer($container);
$first = $reflection->getMethod('createDraft')->invoke($controller, $series);
check($first->getData() === $data, 'New standalone report receives imported dimensions.');
$first->setData(array_replace_recursive($data, ['height_full' => '99', 'measurement_parts' => [1 => ['weight_without_frame' => '2']], 'recommendation' => 'Keep dry']));
$first->finalize(null);
$manager->flush();
$before = $first->getData();
$next = $reflection->getMethod('createDraft')->invoke($controller, $series);
check($next->getData()['height_full'] === '40', 'A new report prefers current Datahub measurements over earlier manual dimensions.');
check($next->getData()['recommendation'] === 'Keep dry' && $next->getData()['measurement_parts'][1]['weight_without_frame'] === '2', 'Copy other report values, including measurements missing from the source.');
check($first->getData() === $before, 'A finalized report must remain unchanged.');

echo "PASS: source priority, units, positional fallback, group mapping, harvest/log persistence and new-report snapshots.\n";
