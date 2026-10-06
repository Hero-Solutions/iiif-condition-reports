<?php

declare(strict_types=1);

// Standalone fixtures only: no application bootstrap, environment files or network.
// Run with php -n, mbstring and intl; allow_url_fopen=0.
use App\Entity\{ObjectRecord, Report, ReportSeries};
use App\Controller\ReportController;
use App\Service\ReportDocumentBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\ArrayLoader as TwigArrayLoader, TwigFilter};

require __DIR__ . '/../vendor/composer/ClassLoader.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require __DIR__ . '/../vendor/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
$loader->register();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$measurements = Yaml::parse(file_get_contents($root . '/config/packages/report_form.yaml'))['parameters']['report_fields']['measurements'];
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
}
$builder = new ReportDocumentBuilder($translator);
$object = new ObjectRecord('TEST-1', 'Test object');
$report = new Report(new ReportSeries($object));
$rows = static function (string $type, array $data) use ($report, $builder, $measurements): array {
    $report->setData($data);
    return $builder->sections($report, ['measurements' => $measurements[$type]])[0]['blocks'][0]['rows'] ?? [];
};

$paintingData = [
    'number_of_parts' => '2',
    'height_full' => '10', 'width_full' => '10', 'depth_full' => '15',
    'measurement_parts' => [
        1 => [
            'height_with_frame' => '20', 'width_with_frame' => '10', 'depth_with_frame' => '15',
            'height_without_frame' => '15', 'width_without_frame' => '8', 'depth_without_frame' => '12',
        ],
        2 => ['height_with_frame' => '30', 'width_with_frame' => '20', 'depth_with_frame' => '5'],
    ],
];
$painting = $rows('painting', $paintingData);
check(count($painting) === 5, 'Only populated groups must occupy a row, plus the part count.');
check(array_column($painting, 'label') === ['Aantal onderdelen', 'Met lijst, volledig werk', 'Onderdeel 1 — Met lijst', 'Onderdeel 1 — Zonder lijst', 'Onderdeel 2 — Met lijst'], 'Groups must identify their part.');
check(array_column($painting, 'values') === [['2'], ['10 × 10 × 15 cm'], ['20 × 10 × 15 cm'], ['15 × 8 × 12 cm'], ['30 × 20 × 5 cm']], 'Dimensions must stay independent for each part.');
check(count($rows('painting', array_replace($paintingData, ['number_of_parts' => '1']))) === 4, 'Parts outside the count must not be printed.');
check(count($rows('painting', array_replace($paintingData, ['number_of_parts' => '0']))) === 2, 'Zero parts must keep the whole-work measurements.');
$reordered = $paintingData;
$reordered['measurement_parts'] = array_reverse($reordered['measurement_parts'], true);
check($rows('painting', $reordered) === $painting, 'Part order must remain numerical after saving.');

// Both save routes use this method. Exercise it without constructing any live services.
$controllerClass = new ReflectionClass(ReportController::class);
$controller = $controllerClass->newInstanceWithoutConstructor();
$controllerClass->getMethod('applySubmittedReport')->invoke($controller, $report, new Request([], ['report_data' => $paintingData]));
$reloaded = json_decode(json_encode($report->getData(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
check($reloaded === $paintingData, 'Nested measurements must round-trip through submission and JSON storage.');
check($rows('painting', ['height_full' => '10', 'depth_full' => '15'])[0]['values'] === ['10 × — × 15 cm'], 'Missing width must not be mistaken for depth.');
check($rows('painting', ['width_full' => '10'])[0]['values'] === ['— × 10 × — cm'], 'Preserve the axis of a single measurement.');
check($rows('painting', ['height_full' => '0', 'width_full' => '12,5', 'depth_full' => '0.5'])[0]['values'] === ['0 × 12,5 × 0.5 cm'], 'Zero and decimals must remain intact.');
check($rows('painting', ['weight_full' => '1,5'])[0]['values'] === ['Gewicht: 1,5 kg'], 'Weight-only groups must remain visible in kg.');
check($rows('painting', ['number_of_parts' => '2'])[0]['values'] === ['2'], 'Part counts must remain unitless.');
check($rows('work_on_paper', ['number_of_parts' => '1', 'measurement_parts' => [1 => ['height_passe_partout' => '10', 'width_passe_partout' => '20']]])[1] === ['label' => 'Onderdeel 1 — Passe-partout', 'values' => ['10 × 20 cm']], 'Two-dimensional groups must not invent a depth.');
check($rows('sculpture', ['number_of_parts' => '1', 'measurement_parts' => [1 => ['type' => 'Sokkel', 'height' => '10', 'width' => '20', 'depth' => '30', 'weight' => '2']]])[1] === ['label' => 'Onderdeel 1 — Sokkel', 'values' => ['10 × 20 × 30 cm', 'Gewicht: 2 kg']], 'Preserve part specification and separate weight from dimensions.');
check($rows('sculpture', ['number_of_parts' => '1', 'measurement_parts' => [1 => ['type' => 'Sokkel']]])[1] === ['label' => 'Onderdeel 1', 'values' => ['Sokkel']], 'Do not discard a specification without dimensions.');
foreach (array_keys($measurements) as $type) {
    check($rows($type, []) === [], 'Empty measurement sections must stay hidden: ' . $type);
    check($rows($type, ['height_full' => ' ', 'weight_full' => '']) === [], 'Whitespace must not create a section.');
}

$templates = [];
foreach (['report_form_renderer', '_document', '_damage_annotations', 'pdf'] as $name) {
    $templates['reports/' . $name . '.html.twig'] = file_get_contents($root . '/templates/reports/' . $name . '.html.twig');
}
$templates['fixture'] = "{% import 'reports/report_form_renderer.html.twig' as form %}{{ form.render_measurements(measurements, data) }}";
$twig = new Environment(new TwigArrayLoader($templates), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFilter(new TwigFilter('report_title', static fn ($report) => 'Test report'));
foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    $form = $twig->render('fixture', ['measurements' => $measurements['painting'], 'data' => $reloaded]);
    check(str_contains($form, 'name="report_data[height_full]" value="10"'), 'Whole-work fields must keep their values.');
    check(str_contains($form, 'name="report_data[measurement_parts][1][height_with_frame]" value="20"'), 'First part must be restored into its own inputs.');
    check(str_contains($form, 'name="report_data[measurement_parts][2][height_with_frame]" value="30"'), 'Second part must be restored into its own inputs.');
    check(str_contains($form, 'name="report_data[measurement_parts][2][height_without_frame]"'), 'Every painting part needs both frame groups.');
    $reduced = $twig->render('fixture', ['measurements' => $measurements['painting'], 'data' => array_replace($paintingData, ['number_of_parts' => '1'])]);
    check(!str_contains($reduced, 'data-measurement-part="2"'), 'Hidden parts must not be submitted after reopening.');
    foreach ($measurements as $definition) {
        $form = $twig->render('fixture', ['measurements' => $definition, 'data' => []]);
        check(str_contains($form, $translator->trans('Height') . ' (cm)'), 'Length inputs must specify cm.');
        check(str_contains($form, $translator->trans('Weight') . ' (kg)') || str_contains($form, $translator->trans('Total weight') . ' (kg)'), 'Weight inputs must specify kg.');
        check(str_contains($form, 'data-numeric-input="decimal"'), 'Decimal entry must remain enabled.');
    }
    foreach ([Report::TYPE_QUICK_CHECK, Report::TYPE_INCOMING_CONDITION] as $type) {
        $documentReport = (new Report(new ReportSeries($object), $type))->setData($paintingData);
        $context = [
            'report' => $documentReport, 'object' => $object, 'app' => ['request' => ['locale' => $locale]],
            'thumbnail_source' => null, 'room_label' => null, 'reason_label' => null, 'author_name' => 'Test', 'finalizer_name' => null,
            'report_actors' => [], 'report_image_sources' => [], 'report_documents' => [], 'damage_annotations' => ['legend' => [], 'sources' => []],
            'report_sections' => $builder->sections($documentReport, ['measurements' => $measurements['painting']]),
        ];
        foreach (['reports/_document.html.twig', 'reports/pdf.html.twig'] as $template) {
            $html = $twig->render($template, $context);
            check(str_contains($html, '10 × 10 × 15 cm'), 'Compact dimensions missing from ' . $template);
            check(str_contains($html, $translator->trans('Without frame')), 'Measurement group missing from ' . $template);
            check(str_contains($html, $translator->trans('reports.measurement_part', ['%number%' => 2]) . ' — ' . $translator->trans('With frame')), 'Part numbering missing from ' . $template);
        }
    }
}
echo "PASS: grouped H × W × D, cm/kg, partial/empty/2D measurements, zero/decimals, sculpture parts and NL/EN form/report/PDF templates.\n";
