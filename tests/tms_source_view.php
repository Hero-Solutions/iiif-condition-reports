<?php
declare(strict_types=1);

// Offline Twig/YAML and entity checks. No kernel, configuration secrets or HTTP.
use App\Entity\{Actor, Project, ProjectActor};
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\FilesystemLoader, TwigFilter, TwigFunction};

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->register();

function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
}
$twig = new Environment(new FilesystemLoader($root . '/templates'), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFilter(new TwigFilter('report_title', static fn(): string => 'Fixture report'));
$twig->addFunction(new TwigFunction('path', static fn(string $route, array $params): string => '/fixture/' . ($params['id'] ?? '0')));
$twig->addFunction(new TwigFunction('is_granted', static fn(): bool => false));
$twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'fixture-token'));
foreach (['projects/form.html.twig', 'projects/index.html.twig'] as $template) {
    $twig->parse($twig->tokenize(new \Twig\Source(file_get_contents($root . '/templates/' . $template), $template)));
}
$project = new Project('Fixture', Project::TYPE_LOAN);
$project->applyTmsSource('loans', '10', ['record' => ['LoanIn' => '1', 'LoanStatus' => 'Closed',
    'LoanNumber' => '0001', 'LoanConditions' => '<script>invalid()</script>'],
    'parties' => [['DisplayName' => 'Fictieve bruikleengever', 'Role' => 'Lender', 'City' => 'Antwerpen']],
    'documents' => [['PrimaryFileName' => null, 'DisplayFileName' => 'fixture.pdf']],
    'shipments' => [['ShipmentNumber' => 'SHIP-1']]], new DateTimeImmutable('2026-10-18 12:00:00'));
$html = $twig->render('projects/_tms_source.html.twig', ['project' => $project,
    'related_projects' => [new Project('Exhibition fixture', Project::TYPE_EXHIBITION)], 'app' => ['request' => ['locale' => 'nl']]]);
check(str_contains($html, 'Fictieve bruikleengever') && str_contains($html, 'Antwerpen') && str_contains($html, 'fixture.pdf'), 'Source context missing.');
check(str_contains($html, 'Exhibition fixture') && str_contains($html, 'SHIP-1') && str_contains($html, 'Closed'), 'Relationships/status missing.');
check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Source text must be escaped.');
$html = $twig->render('projects/_tms_object_context.html.twig', ['source' => ['records' => [['SpecialConditions' => 'Fragile & delicate', 'Remarks' => '<b>plain</b>']]]]);
check(str_contains($html, 'Fragile &amp; delicate') && str_contains($html, '&lt;b&gt;plain&lt;/b&gt;'), 'Object conditions must be escaped.');
foreach (['nl', 'en'] as $locale) {
    foreach (['source_details', 'missing_link', 'local_edits', 'document_metadata', 'courier'] as $key) {
        check($translator->trans('tms_sync.' . $key, [], null, $locale) !== 'tms_sync.' . $key, 'Missing translation: ' . $locale . '/' . $key);
    }
}
$services = Yaml::parse(file_get_contents($root . '/config/services.yaml'));
// The shared assignment template must also work with imported actors and their source status.
$actor = (new Actor('<script>actor</script>', Actor::TYPE_UNKNOWN))->setAddress('Street & square');
$assignment = (new ProjectActor($project, $actor))->setRole('owner_lender');
$assignment->applyTmsSource('601', $actor, []);
$actorContext = ['actors' => [$assignment], 'empty_message' => 'actors.empty',
    'remove_route' => 'fixture', 'remove_params' => [], 'remove_token_prefix' => 'fixture'];
$html = $twig->render('projects/_actor_assignment_list.html.twig', $actorContext);
check(str_contains($html, 'Uit TMS') && str_contains($html, 'Type nog te bepalen') && str_contains($html, 'Street &amp; square'), 'Imported actor label/type/address missing.');
check(str_contains($html, '&lt;script&gt;actor') && !str_contains($html, '<script>actor'), 'Actor name must be escaped.');
(new ReflectionProperty(ProjectActor::class, 'tmsPresent'))->setValue($assignment, false);
$html = $twig->render('projects/_actor_assignment_list.html.twig', $actorContext);
check(str_contains($html, 'Niet meer aangeleverd'), 'Missing source assignment warning.');
check(Actor::typeChoices()['actor_type.unknown'] === Actor::TYPE_UNKNOWN, 'Unknown source type must remain editable.');
check($services['services']['App\\Service\\TmsProjectSync']['arguments']['$workDirectory'] === '%kernel.project_dir%/var/tms-sync', 'Importer must use a private local work directory.');
check(Yaml::parse(file_get_contents($root . '/config/packages/tms.yaml'))['parameters']['env(EXTERNAL_LOANS_ENABLED)'] === '0', 'Import must remain disabled by default.');
echo "OK: TMS views, escaping, translations and service wiring; no kernel or network.\n";
