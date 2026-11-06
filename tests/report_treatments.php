<?php

declare(strict_types=1);

// Standalone fixtures only. Run with PHP 8.4, mbstring and intl, without app bootstrap.
use App\Controller\ReportController;
use App\Entity\{ObjectRecord, Report, ReportSeries};
use App\Service\{ReportDocumentBuilder, ReportFormDefinition};
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\FilesystemLoader, TwigFilter};

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
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

$parameters = Yaml::parseFile($root . '/config/packages/report_form.yaml')['parameters']
    + Yaml::parseFile($root . '/config/packages/report_compact_form.yaml')['parameters'];
$object = new ObjectRecord('TEST-1', 'Test sculpture');
$object->setObjectType(ObjectRecord::TYPE_SCULPTURE);
$definition = (new ReportFormDefinition(new ParameterBag($parameters)))->forObject($object);
$report = new Report(new ReportSeries($object));

$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parseFile($root . '/translations/messages.' . $locale . '.yaml'), $locale);
}

$twig = new Environment(new FilesystemLoader($root . '/templates'), ['strict_variables' => true]);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$editor = $twig->createTemplate("{% import 'reports/report_form_renderer.html.twig' as form %}{{ form.render_compact_section(section, data) }}");
$renderPicker = static function (array $data) use ($editor, $definition): Dom\Element {
    $html = $editor->render(['section' => $definition['sections']['condition'], 'data' => $data]);
    return Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('.compact-treatment-picker');
};

$picker = $renderPicker([]);
check($picker->querySelectorAll('[data-compact-option]')->length === 5, 'Offer the concrete preset treatments.');
check($picker->querySelectorAll('fieldset:not([hidden])')->length === 0, 'A new report shows no treatment rows.');
check($picker->querySelectorAll('fieldset[hidden][disabled]')->length === 5, 'Unselected controls do not submit values.');

$prefix = 'condition_sculpture_specific_condition_';
$stored = [
    $prefix . 'conservation_treatment_necessary_necessary' => '1',
    '__enabled_' . $prefix . 'fixating_necessary_not_necessary' => '1',
    $prefix . 'cleaning_happened_radio_happened_date' => '2026-11-06',
    $prefix . 'cleaning' => '0',
    $prefix . 'custom_details' => 'Earlier custom treatment',
];
check($renderPicker($stored)->querySelectorAll('fieldset:not([hidden])')->length === 4, 'Show existing treatment data, including general status and earlier custom details.');

$submitted = $stored + [
    '__selected_treatment_' . $prefix . 'research' => '1',
    'sculpture_custom_treatments' => [
        0 => ['name' => 'Protective layer <test>', 'necessary_necessary' => '1', 'happened_happened' => '1', 'date' => '2026-11-06', 'hours' => '1,5', 'details' => 'First custom treatment'],
        2 => ['name' => 'Wax removal', 'necessary_not_necessary' => '1', 'happened_not_happened_yet' => '1'],
    ],
];
$controller = new ReflectionClass(ReportController::class);
$controller->getMethod('applySubmittedReport')->invoke(
    $controller->newInstanceWithoutConstructor(),
    $report,
    new Request([], ['report_data' => $submitted]),
);
$reopened = json_decode(json_encode($report->getData(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
check($reopened === $submitted, 'Preset and custom entries must round-trip together.');

foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    $picker = $renderPicker($reopened);
    check($picker->querySelectorAll('fieldset:not([hidden])')->length === 7, 'Reopen both custom rows and a preset without a status.');
    $custom = $picker->querySelector('fieldset[data-compact-item="custom-treatment-0"]');
    check($custom->querySelector('h4')->textContent === $submitted['sculpture_custom_treatments'][0]['name'], 'Show a custom name as plain text.');
    check($custom->querySelectorAll('[aria-pressed="true"]')->length === 2, 'A treatment can be both necessary and performed.');
    check($custom->querySelector('[data-treatment-date-field]:not([hidden]) input')->getAttribute('value') === '2026-11-06', 'Restore the date within the right treatment.');
    check($picker->querySelector('input[name="report_data[sculpture_custom_treatments][2][name]"]')->getAttribute('value') === 'Wax removal', 'Custom rows keep their own input names.');

    $blocks = (new ReportDocumentBuilder($translator))->sections($report, $definition)[0]['blocks'];
    $rows = array_column($blocks[0]['rows'], 'values', 'label');
    check(isset($rows[$translator->trans('Conservation treatment')]), 'Keep the earlier overall status in the document.');
    check(in_array($translator->trans('reports.number_of_hours') . ': 0', $rows[$translator->trans('Cleaning')], true), 'Preserve zero hours in the document.');
    check($rows['Protective layer <test>'] === [
        $translator->trans('Necessary'), $translator->trans('Happened'),
        $translator->trans('reports.treatment_date') . ': 2026-11-06',
        $translator->trans('reports.number_of_hours') . ': 1,5',
        $translator->trans('reports.further_explanation') . ': First custom treatment',
    ], 'Show custom treatment details under their own name in the document.');
    check(count($rows['Wax removal']) === 2, 'Keep the statuses of a second custom treatment separate.');
    check($rows[$translator->trans('Further research needed')] === [], 'A selected preset can remain unassessed.');
}

echo "PASS: sculpture treatment picker, legacy fields, independent statuses, custom rows and report output in NL and EN.\n";
