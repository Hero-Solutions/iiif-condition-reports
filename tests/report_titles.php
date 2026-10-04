<?php

declare(strict_types=1);

// Standalone checks: no application bootstrap, environment files or network.
// Run with php -n and the mbstring, intl and pdo_sqlite extensions.
use App\Controller\PageController;
use App\Controller\ReportController;
use App\Entity\{Actor, ObjectRecord, OrganizationContact, Project, Report, ReportActor, ReportDocument, ReportImage, ReportSeries};
use App\Service\{ReportAuthorProvider, ReportFormDefinition, ReportTitleFormatter};
use App\Twig\ReportExtension;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\{Container, ParameterBag\ParameterBag};
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\ArrayLoader as TwigArrayLoader, Loader\ChainLoader, Loader\FilesystemLoader, TwigFilter, TwigFunction};

require __DIR__ . '/../vendor/composer/ClassLoader.php';
require __DIR__ . '/../vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require __DIR__ . '/../vendor/composer/autoload_psr4.php' as $namespace => $paths) {
    $loader->addPsr4($namespace, $paths);
}
$loader->register();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$parameters = Yaml::parseFile($root . '/config/packages/report_form.yaml')['parameters'];
$definition = new ReportFormDefinition(new ParameterBag($parameters));
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parseFile($root . '/translations/messages.' . $locale . '.yaml'), $locale);
}
$titles = new ReportTitleFormatter($definition, $translator);
$object = new ObjectRecord('TEST-123', 'Fixture object');
$series = new ReportSeries($object, 'Old manual title');
$report = new Report($series);
(new ReflectionProperty(Report::class, 'createdAt'))->setValue($report, new DateTimeImmutable('2026-09-25 12:00:00'));
(new ReflectionProperty(Report::class, 'title'))->setValue($report, 'OLD INVENTORY TITLE');
check($titles->format($report) === 'Conditierapport — 25/09/2026', 'Fallback must ignore the legacy title.');
$translator->setLocale('en');
check($titles->format($report) === 'Condition report — 25/09/2026', 'English fallback is missing.');
$report->setStartedAt(new DateTimeImmutable('2026-09-24'));

// Include duplicate labels with distinct codes, as found in older reports.
foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    foreach ($parameters['report_reasons'] as $group) {
        foreach ($group['options'] as $code => $label) {
            $report->setReason((string) $code);
            check($titles->format($report) === $translator->trans($label) . ' — 24/09/2026', 'Reason not resolved: ' . $code);
        }
    }
}
$translator->setLocale('nl');
$report->setReason('other')->setCustomReason('  Controle na transport  ');
check($titles->format($report) === 'Controle na transport — 24/09/2026', 'Custom reason must be used verbatim.');
$report->setCustomReason(null);
check($titles->format($report) === 'Conditierapport — 24/09/2026', 'Empty custom reason needs a fallback.');
$report->setReason('unrecognized');
check($titles->format($report) === 'Conditierapport — 24/09/2026', 'Unknown reason needs a fallback.');

$controllerReflection = new ReflectionClass(ReportController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$container = new Container();
$container->set('security.token_storage', new Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage());
$controller->setContainer($container);
$controllerReflection->getProperty('reportTitles')->setValue($controller, $titles);
$submit = $controllerReflection->getMethod('applySubmittedReport');
$submit->invoke($controller, $report, new Request([], [
    'title' => 'User-supplied override', 'type' => 'quick_condition_check',
    'reason' => 'other', 'custom_reason' => 'Transportcontrole', 'started_at' => '2026-10-03', 'ended_at' => '2026-10-05',
]));
check($titles->format($report) === 'Transportcontrole — 03/10/2026', 'Submitted titles must be ignored.');
$report->finalize(null);
check($titles->format($report) === 'Transportcontrole — 03/10/2026', 'Finalized titles must also be derived.');
$report->archive();
check($titles->format($report) === 'Transportcontrole — 03/10/2026', 'Archived titles must also be derived.');

// Exercise real draft creation and exports against an isolated in-memory schema.
$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true);
$config->setAutoGenerateProxyClasses(false);
$config->setNamingStrategy(new Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
$manager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
$classes = [ObjectRecord::class, Project::class, ReportSeries::class, Report::class,
    Actor::class, OrganizationContact::class, ReportActor::class, ReportImage::class, ReportDocument::class];
(new SchemaTool($manager))->createSchema(array_map($manager->getClassMetadata(...), $classes));
$manager->persist($object);
$manager->persist($series);
$manager->persist($report);
$manager->flush();
$controllerReflection->getProperty('entityManager')->setValue($controller, $manager);
$createDraft = $controllerReflection->getMethod('createDraft');
$draft = $createDraft->invoke($controller, $series);
$manager->flush();
check($draft->getBasedOnReport() === $report, 'Draft must retain its source report.');
check($draft->getStartedAt()->format('Y-m-d') === date('Y-m-d'), 'New draft must use its own inspection date.');
check($draft->getEndedAt() === null, 'New draft must not inherit an old end date.');
check($titles->format($draft) === 'Transportcontrole — ' . date('d/m/Y'), 'New draft title copied an old date.');

$freshObject = new ObjectRecord('TEST-456', 'Second fixture object');
$freshSeries = new ReportSeries($freshObject, 'Another legacy title');
$manager->persist($freshObject);
$manager->persist($freshSeries);
$manager->flush();
$freshDraft = $createDraft->invoke($controller, $freshSeries);
$manager->flush();
check($titles->format($freshDraft) === 'Conditierapport — ' . date('d/m/Y'), 'First draft fallback is incorrect.');

$request = new Request();
$request->setLocale('nl');
$csv = (new PageController())->reportsCsv($request, $manager, new ReportAuthorProvider($manager), $titles)->getContent();
check(str_contains($csv, 'Transportcontrole — 03/10/2026'), 'CSV still uses the stored title.');
check(!str_contains($csv, 'OLD INVENTORY TITLE'), 'Legacy title leaked into CSV.');
$json = json_decode($controller->exportJson($report)->getContent(), true, flags: JSON_THROW_ON_ERROR);
check($json['title'] === 'Transportcontrole — 03/10/2026', 'JSON title is inconsistent.');

$twig = new Environment(new ChainLoader([
    new TwigArrayLoader(['base.html.twig' => '{% block title %}{% endblock %}{% block body %}{% endblock %}']),
    new FilesystemLoader($root . '/templates'),
]), ['strict_variables' => false, 'autoescape' => 'html']);
$twig->addExtension(new ReportExtension($titles));
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
foreach (['path', 'csrf_token', 'form_start', 'form_end', 'form_row', 'form_widget', 'form_errors', 'form_label'] as $function) {
    $twig->addFunction(new TwigFunction($function, static fn (...$args): string => 'fixture'));
}
$twig->addFunction(new TwigFunction('is_granted', static fn (...$args): bool => false));
$templates = ['reports/form.html.twig', 'reports/show.html.twig', 'reports/index.html.twig',
    'reports/_document.html.twig', 'reports/existing_draft.html.twig', 'reports/project_choice.html.twig',
    'reports/link_unlinked.html.twig', 'projects/form.html.twig', 'objects/form.html.twig'];
foreach ($templates as $template) {
    $twig->parse($twig->tokenize($twig->getLoader()->getSourceContext($template)));
}
$form = file_get_contents($root . '/templates/reports/form.html.twig');
check(!str_contains($form, 'name="title"'), 'Editable report title remains in the form.');
$draft->setReason('other')->setCustomReason('<script>alert("title")</script>');
$renderedTitle = $twig->createTemplate('{{ report|report_title }}')->render(['report' => $draft]);
check(str_contains($renderedTitle, '&lt;script&gt;') && !str_contains($renderedTitle, '<script>'), 'Generated titles must be escaped.');
$html = $twig->render('reports/index.html.twig', [
    'app' => ['request' => $request], 'reports' => [$report, $draft], 'selected_project' => null,
    'q' => '', 'thumbnail_urls' => [], 'report_rooms' => [], 'report_author_names' => [],
]);
check(str_contains($html, 'Transportcontrole — 03/10/2026'), 'Report overview title is inconsistent.');
check(!str_contains($html, 'OLD INVENTORY TITLE'), 'Overview still reads the legacy title.');
foreach ([Report::TYPE_QUICK_CHECK, Report::TYPE_INCOMING_CONDITION] as $documentType) {
    $documentReport = (new Report($series, $documentType))->setReason('other')->setCustomReason('Transportcontrole')->setStartedAt(new DateTimeImmutable('2026-10-03'));
    $document = $twig->render('reports/_document.html.twig', [
        'app' => ['request' => $request], 'report' => $documentReport, 'object' => $object,
        'reason_label' => $titles->reason($report), 'report_actors' => [], 'report_sections' => [],
        'report_images' => [], 'report_documents' => [], 'damage_annotations' => ['legend' => [], 'sources' => []],
    ]);
    check(str_contains($document, '<h1>Transportcontrole — 03/10/2026</h1>'), 'Document title is inconsistent.');
}

echo "PASS: automatic titles, NL/EN reasons, date fallbacks, protected titles, new drafts, CSV/JSON, Twig and document output.\n";
