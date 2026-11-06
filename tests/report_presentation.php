<?php

declare(strict_types=1);

// Standalone form and document fixtures. No application bootstrap, environment files or network.
use App\Entity\{ObjectRecord, Report, ReportSeries};
use App\Service\{ReportDocumentBuilder, ReportFormDefinition};
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
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
$renderPresentation = static function (array $data) use ($editor, $definition): Dom\Element {
    $html = $editor->render(['section' => $definition['sections']['recommendations'], 'data' => $data]);
    return Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)
        ->querySelector('section[aria-labelledby="recommendations-block-0-title"]');
};

$prefix = 'recommendations_sculpture_presentation_';
$selected = [
    '__enabled_' . $prefix . 'setup_standing' => '1',
    $prefix . 'setup_standing_on_pedestal' => '1',
    $prefix . 'setup_standing_on_pedestal_stabilizing' => '1',
    $prefix . 'security_conditions_mechanical_protection_security_screws' => '1',
    $prefix . 'setup_hanging_system_present' => '1',
    $prefix . 'tools_setup_yes' => '1',
    $prefix . 'setup_equipment_pallet_truck' => '1',
    $prefix . 'setup_equipment_other_text' => 'Fictieve montagehulp',
];

foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    $empty = $renderPresentation([]);
    check($empty->querySelectorAll('fieldset[data-compact-requires][hidden][disabled]')->length === 5, 'Hide and disable details until the corresponding choice is made.');
    check($empty->querySelector('input[name="report_data[' . $prefix . 'tools_setup_yes]"]') !== null, 'Offer an explicit yes for setup equipment.');
    check($empty->querySelector('input[name="report_data[' . $prefix . 'security_conditions_mechanical_protection]"]') === null, 'Omit the duplicate generic mechanical checkbox.');
    check($empty->querySelector('input[name="report_data[' . $prefix . 'setup_hanging_system]"]') === null, 'Omit the duplicate generic hanging system checkbox.');

    $filled = $renderPresentation($selected);
    check($filled->querySelectorAll('fieldset[data-compact-requires]:not([hidden]):not([disabled])')->length === 3, 'Show only the standing placement, pedestal and equipment details.');
    $equipment = $filled->querySelector('fieldset[data-compact-requires="' . $prefix . 'tools_setup_yes' . '"]');
    $labels = array_map(static fn (Dom\Element $option): string => trim($option->textContent), iterator_to_array($equipment->querySelectorAll('[data-compact-option]')));
    check($labels === array_map($translator->trans(...), ['Car with pneumatic tires', 'Pallet truck', 'Trolley', 'Telehandler', 'Stacker', 'Gantry crane']), 'Use the equipment list from the original form.');

    $builder = new ReportDocumentBuilder($translator);
    $report->setData($selected);
    $rows = array_column($builder->sections($report, $definition)[0]['blocks'][0]['rows'], 'values', 'label');
    check($rows[$translator->trans('Display position')] === [$translator->trans('Standing')], 'Use the display position label in the report.');
    check($rows[$translator->trans('Security conditions')] === [$translator->trans('Security screws')], 'Print detailed mechanical choices under security.');
    check(!isset($rows[$translator->trans('Hanging system')]), 'Omit a hanging system when no hanging position is selected.');
    check($rows[$translator->trans('Setup equipment')] === [$translator->trans('Pallet truck'), 'Fictieve montagehulp'], 'Keep both preset and custom equipment in the report.');

    $report->setData(array_diff_key($selected, [$prefix . 'tools_setup_yes' => true]) + [$prefix . 'tools_setup_no' => '1']);
    $rows = array_column($builder->sections($report, $definition)[0]['blocks'][0]['rows'], 'values', 'label');
    check($rows[$translator->trans('Tools needed for setup')] === [$translator->trans('No')], 'Keep the explicit no in the report.');
    check(!isset($rows[$translator->trans('Setup equipment')]), 'Omit equipment when the answer is no.');
}

echo "PASS: sculpture presentation options, dependent controls and report output in NL and EN.\n";
