<?php

declare(strict_types=1);

// Isolated entities and templates only. No application bootstrap, credentials or network.
use App\Entity\{Actor, ObjectRecord, Project, ProjectObject, Report, ReportActor, ReportSeries};
use App\Service\ReportDocumentBuilder;
use App\Value\ActorRole;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\ArrayLoader as TwigArrayLoader, TwigFilter, TwigFunction};

require __DIR__ . '/../vendor/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require __DIR__ . '/../vendor/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
$loader->addClassMap(require __DIR__ . '/../vendor/composer/autoload_classmap.php');
$loader->register();

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
}
$builder = new ReportDocumentBuilder($translator);
$project = (new Project('Voorbeeldtentoonstelling'))->setReferenceCode('BR-2026-017');
$object = new ObjectRecord('INV-123', 'Testobject');
$series = new ReportSeries($object, '', $project);
$report = new Report($series);
$projectObject = (new ProjectObject($project, $object))->setRoom('Zaal 4')->setNotes("Voorzichtig hanteren.\nSamen met de bijbehorende sokkel plaatsen.");
$lender = (new Actor('Museum van de Voorbeeldcollectie'))->setAlias('Afkorting');
$borrower = new Actor('Stedelijk Museum voor Beeldende Kunst');
$actors = [
    (new ReportActor($report, $lender))->setRole(ActorRole::OWNER_LENDER),
    (new ReportActor($report, $lender))->setRole(ActorRole::OWNER_LENDER),
    (new ReportActor($report, $borrower))->setRole(ActorRole::BORROWER),
    (new ReportActor($report, new Actor('Andere bruikleengever')))->setRole(ActorRole::OWNER_LENDER),
    (new ReportActor($report, new Actor('Restaurator')))->setRole(ActorRole::CONSERVATOR),
];
$details = $builder->contextDetails($report, $projectObject, $actors);
check($details['projects.reference_code'] === 'BR-2026-017', 'Use the project reference.');
check($details['projects.room'] === 'Zaal 4' && $details['reports.object_notes'] === $projectObject->getNotes(), 'Use room and notes from the object within this project.');
check($details['actor_role.owner_lender'] === "Museum van de Voorbeeldcollectie\nAndere bruikleengever", 'Use full names, deduplicate contact assignments and keep all lenders.');
check($details['actor_role.borrower'] === $borrower->getName(), 'Include the borrower.');
check(!in_array('Restaurator', $details, true), 'Do not add unrelated actor roles to the header.');
$standalone = new Report(new ReportSeries($object));
check($builder->contextDetails($standalone, null, []) === [], 'An unlinked report needs no empty context.');
check(count($builder->contextDetails($standalone, null, $actors)) === 2, 'Parties remain available without a project.');
check($builder->contextDetails($report, null, []) === ['projects.reference_code' => 'BR-2026-017'], 'A missing project-object link must not lose the project reference.');
$project->setReferenceCode('0');
$projectObject->setRoom('0')->setNotes('0');
check(count($builder->contextDetails($report, $projectObject, [])) === 3, 'Zero is a valid reference, room or note.');
$project->setReferenceCode('BR-2026-017');
$projectObject->setRoom('Zaal 4')->setNotes($details['reports.object_notes']);

$project->setStartDate(new DateTimeImmutable('2026-10-01'))->setEndDate(new DateTimeImmutable('2027-01-12'));
$report->setStartedAt(new DateTimeImmutable('2026-10-06'))->setEndedAt(new DateTimeImmutable('2026-10-07'));
$details = $builder->contextDetails($report, $projectObject, $actors);
check($details['projects.start_date'] === '01/10/2026' && $details['projects.end_date'] === '12/01/2027', 'Header dates must come from the project, not the inspection.');
$project->setStartDate(null);
$partial = $builder->contextDetails($report, $projectObject, []);
check(!isset($partial['projects.start_date']) && $partial['projects.end_date'] === '12/01/2027', 'A missing project start date must not use the report date.');
$project->setEndDate(null);
$partial = $builder->contextDetails($report, $projectObject, []);
check(!isset($partial['projects.start_date']) && !isset($partial['projects.end_date']), 'Unspecified project dates must stay absent.');
$project->setStartDate(new DateTimeImmutable('2026-10-01'));
$partial = $builder->contextDetails($report, $projectObject, []);
check($partial['projects.start_date'] === '01/10/2026' && !isset($partial['projects.end_date']), 'A project start date must work without an end date.');
$project->setEndDate(new DateTimeImmutable('2026-10-01'));
$sameDay = $builder->contextDetails($report, $projectObject, []);
check($sameDay['projects.start_date'] === $sameDay['projects.end_date'], 'Both labels must remain for a one-day project.');
$project->setEndDate(new DateTimeImmutable('2027-01-12'));

$templates = [];
foreach (['_context_details', '_document_context', '_document', '_damage_annotations', 'pdf', 'form'] as $name) {
    $templates['reports/' . $name . '.html.twig'] = file_get_contents($root . '/templates/reports/' . $name . '.html.twig');
}
$twig = new Environment(new TwigArrayLoader($templates), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFilter(new TwigFilter('report_title', static fn ($report) => 'Transportcontrole — 09/10/2026'));
foreach (['path', 'csrf_token', 'is_granted'] as $function) {
    $twig->addFunction(new TwigFunction($function, static fn (...$args): string => 'fixture'));
}
$twig->parse($twig->tokenize($twig->getLoader()->getSourceContext('reports/form.html.twig')));

foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    foreach ([Report::TYPE_QUICK_CHECK, Report::TYPE_INCOMING_CONDITION] as $type) {
        foreach ([false, true] as $finalized) {
            $document = (new Report($series, $type))->setStartedAt(new DateTimeImmutable('2026-10-06'))->setEndedAt(new DateTimeImmutable('2026-10-07'));
            if ($finalized) $document->finalize(1);
            $context = [
                'app' => ['request' => ['locale' => $locale]], 'report' => $document, 'object' => $object,
                'report_context' => $details, 'report_actors' => [], 'report_sections' => [],
                'thumbnail_source' => null, 'reason_label' => null, 'author_name' => 'Testpersoon', 'finalizer_name' => 'Testpersoon',
                'report_image_sources' => [], 'report_documents' => [
                    new App\Entity\ReportDocument($document, '/fixture-basis.pdf', 'Basis fixture.pdf', 'application/pdf'),
                    (new App\Entity\ReportDocument($document, '/fixture-recommendations.pdf', 'Recommendations fixture.pdf', 'application/pdf'))->setSection('recommendations'),
                ], 'damage_annotations' => ['legend' => [], 'sources' => []],
            ];
            foreach (['reports/_context_details.html.twig', 'reports/_document.html.twig', 'reports/pdf.html.twig'] as $template) {
                $html = $twig->render($template, $context);
                foreach ($details as $label => $value) {
                    check(str_contains($html, $translator->trans($label)), 'Missing translated context label: ' . $label);
                    foreach (explode("\n", $value) as $line) check(str_contains($html, $line), 'Missing context value in ' . $template);
                }
                check(str_contains($html, '<br'), 'Notes and multiple lenders need preserved line breaks.');
                check(!str_contains($html, 'Afkorting'), 'Full party names must appear instead of aliases.');
                check(!str_contains($html, '06/10/2026') && !str_contains($html, '07/10/2026'), 'Inspection dates must not replace the project dates.');
                check(!str_contains($html, '>Periode<') && !str_contains($html, '>Period<'), 'Remove the ambiguous period label.');
                if ($template !== 'reports/_context_details.html.twig') {
                    foreach ($context['report_documents'] as $attachment) {
                        check(str_contains($html, $attachment->getOriginalName()), 'Keep every attachment in preview/final/PDF.');
                        check(str_contains($html, '(' . $translator->trans($attachment->getSectionTranslationKey()) . ' · '), 'Identify the section of each attachment.');
                    }
                }
            }
            if (in_array('--render-fixtures', $argv, true) && $locale === 'nl' && $finalized) {
                $directory = $root . '/tmp/pdfs/report-context';
                if (!is_dir($directory)) mkdir($directory, 0777, true);
                $pdf = new Dompdf\Dompdf(new Dompdf\Options([
                    'isRemoteEnabled' => false, 'fontCache' => $directory, 'tempDir' => $directory,
                ]));
                $pdf->setPaper('A4');
                $pdf->loadHtml($twig->render('reports/pdf.html.twig', $context), 'UTF-8');
                $pdf->render();
                file_put_contents($directory . '/context-' . $type . '.pdf', $pdf->output());
            }
        }
    }
}

foreach (['reports/_context_details.html.twig', 'reports/_document_context.html.twig'] as $template) {
    check(trim($twig->render($template, ['report_context' => []])) === '', 'Empty context must not add blank labels or rows.');
    $html = $twig->render($template, ['report_context' => ['reports.object_notes' => "<script>alert(1)</script>\nVolgende regel"]]);
    check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Notes must be escaped.');
    check(str_contains($html, 'Volgende regel'), 'Multiline notes must stay visible.');
}
echo "PASS: project reference, room/notes, full party names, duplicates, missing data, zero values, escaping and NL/EN editor/preview/final/PDF templates.\n";
