<?php

declare(strict_types=1);

// Offline template fixtures: no application bootstrap, environment files or HTTP.
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\ArrayLoader as TwigArrayLoader, TwigFilter, TwigFunction};

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
$templates = ['base.html.twig' => '{% block body %}{% endblock %}'];
foreach (['form', '_image_manager', '_frame_schema_picker', 'report_form_renderer', '_section_documents', '_document_upload'] as $name) {
    $templates['reports/' . $name . '.html.twig'] = file_get_contents($root . '/templates/reports/' . $name . '.html.twig');
}
foreach (['reports/_context_details', 'projects/_actor_assignment_list', 'projects/_actor_assignment_form'] as $name) {
    $templates[$name . '.html.twig'] = '';
}
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
$twig = new Environment(new TwigArrayLoader($templates), ['strict_variables' => true]);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFilter(new TwigFilter('report_title', static fn () => 'Fixture report'));
$twig->addFunction(new TwigFunction('path', static fn ($route, $params) => '/' . $route . '?' . http_build_query($params)));
$twig->addFunction(new TwigFunction('csrf_token', static fn ($key) => 'fixture-' . $key));

$object = new App\Entity\ObjectRecord('TEST-1', 'Fixture object');
$series = new App\Entity\ReportSeries($object);
$report = new App\Entity\Report($series);
$choices = [
    ['key' => 'front-rectangle', 'side' => 'front', 'path' => '/fixture-front.svg', 'label' => 'Rectangle', 'imageId' => null],
    ['key' => 'back-rectangle', 'side' => 'back', 'path' => '/fixture-back.svg', 'label' => 'Rectangle', 'imageId' => 7],
];
$context = [
    'report' => $report, 'series' => $series, 'object' => $object,
    'thumbnail_url' => null, 'annotation_manifest_url' => null, 'annotation_image_url' => null, 'annotation_images' => [],
    'can_link_project' => false, 'can_finalize' => false, 'back_url' => '/fixture', 'csrf_token_id' => 'fixture',
    'report_documents' => [], 'report_images' => [], 'damage_case_count' => 0, 'frame_schema_choices' => $choices,
    'report_actors' => [], 'actor_type_choices' => [], 'actor_role_choices' => [], 'document_categories' => [],
    'report_types' => [], 'report_reasons' => [],
    'definition' => ['number_of_parts' => 'reports.number_of_parts', 'measurements' => [], 'damage_types' => [],
        'sections' => [
            'materials' => ['key' => 'materials', 'blocks' => []],
            'condition' => ['key' => 'condition', 'blocks' => []],
            'recommendations' => ['key' => 'recommendations', 'blocks' => []],
        ]],
];

foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
    $translator->setLocale($locale);
    $request = new Request();
    $request->setLocale($locale);
    $context['app'] = ['request' => $request];
    foreach ([false, true] as $hasImages) {
        $fixture = $context;
        if ($hasImages) {
            $fixture['main_image_fingerprint'] = 'fixture-old-image';
            $fixture['archived_main_images'] = [
                ['key' => 'main:1', 'image_url' => '/old-front.jpg', 'thumbnail_url' => '/old-front-thumb.jpg'],
                ['key' => 'main:2', 'image_url' => '/old-back.jpg', 'thumbnail_url' => '/old-back-thumb.jpg'],
            ];
            foreach (['basis', 'recommendations'] as $index => $section) {
                $fixture['report_documents'][] = [
                    'id' => 40 + $index, 'section' => $section, 'path' => '/fixture-' . $section . '.pdf',
                    'originalName' => $section . '.pdf', 'categoryTranslationKey' => null, 'category' => 'Custom', 'size' => 42,
                ];
            }
            $fixture['thumbnail_url'] = $fixture['annotation_image_url'] = '/fixture-main.jpg';
            $fixture['report_images'] = [
                ['id' => 5, 'path' => '/fixture-photo.jpg', 'thumbnailPath' => null, 'originalName' => 'Fixture photo'],
                ['id' => 6, 'path' => '/fixture-other.jpg', 'thumbnailPath' => null, 'originalName' => 'Other photo'],
            ];
        } else {
            $fixture['frame_schema_choices'][1]['imageId'] = null;
        }
        $html = $twig->render('reports/form.html.twig', $fixture);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $count = static fn ($query) => $xpath->query($query)->length;
        check($count('//*[@data-report-tab="photos" or @data-report-tab-panel="photos"]') === 0, 'No Photos editor tab.');
        check($count('//*[@data-report-tab-panel="damage"]//button[@data-report-media-open="report-image-manager"]') === 1, 'Add images with or without existing sources.');
        check($count('//dialog[@id="report-image-manager" and not(@open)]') === 1, 'Image dialog starts closed.');
        check($count('//dialog/ancestor::form') === 0 && $count('//form//form') === 0, 'Upload forms must remain outside the report form.');
        check($count('//section[@class="report-editor"]/dialog') === 1, 'Only images use a dialog.');
        check($count('//dialog[@id="report-image-manager"]//form[@data-report-media-form]') === 4, 'Keep upload, camera, image URL and IIIF.');
        check($count('//dialog[@id="report-image-manager"]//input[@type="file" and @multiple]') === 1, 'Multiple file upload.');
        check($count('//dialog//input[@capture="environment"]') === 1, 'Camera capture.');
        check($count('//dialog//input[@type="url"]/../following-sibling::button[@disabled]') === 2, 'URL loading starts disabled.');
        foreach ($xpath->query('//dialog//button[@form]') as $button) {
            check($count('//form[@id="' . $button->getAttribute('form') . '"]') === 1, 'Frame add/delete buttons retain their forms.');
        }
        if ($hasImages) {
            check($count('//*[@data-main-image-fingerprint="fixture-old-image"]') === 1, 'Submit the identity of the image shown in the editor.');
            check($count('//*[@data-annotation-source and @data-source-key="main:2" and @data-image-url="/old-back.jpg"]') === 1, 'Archived canvases keep their original keys and files.');
            check($count('//*[@data-annotation-source and @data-source-key="main"]') === 0, 'Do not mix the current source into an archived report.');
            foreach (['rotate', 'move', 'delete'] as $action) {
                check($count('//dialog//form[starts-with(@action, "/reports_images_' . $action . '")]') > 0, 'Retain image ' . $action . '.');
            }
        }
        check(str_contains($html, $translator->trans('reports.add_images')), 'Translated dialog title.');
        check($count('//*[@data-report-tab="documents" or @data-report-tab-panel="documents"]') === 0, 'No separate Documents tab.');
        foreach (['basis', 'recommendations'] as $section) {
            $panel = '//*[@data-report-tab-panel="' . $section . '"]';
            $uploadId = 'report-document-upload-' . $section;
            $upload = '//form[@id="' . $uploadId . '"]';
            $fields = $panel . '//*[@data-report-media-fields="' . $uploadId . '"]';
            check($count($panel . '//*[@data-report-media-open]') === 0, 'Document uploads need no popup.');
            check($count($upload . '//input[@name="section" and @value="' . $section . '"]') === 1, 'Submit the selected section.');
            check($count($upload . '/parent::section[@class="report-editor"]') === 1, 'Separate document and report submissions.');
            check($count($fields . '//input[@type="file" and @multiple]') === 1, 'Show multiple document upload inline.');
            foreach ($xpath->query($fields . '//input | ' . $fields . '//select | ' . $fields . '//button') as $control) {
                check($control->getAttribute('form') === $uploadId, 'Inline controls belong to the upload form, not report autosave/validation.');
            }
            check($count($panel . '//*[@data-report-document-row]') === ($hasImages ? 1 : 0), 'Only list documents belonging to this section.');
            if ($hasImages) {
                check($count($panel . '//a[@href="/fixture-' . $section . '.pdf"]') === 1, 'Correct document in its section.');
                foreach ($xpath->query($panel . '//*[@data-report-document-row]//button[@form]') as $button) {
                    check($count('//form[@id="' . $button->getAttribute('form') . '" and @data-report-document-delete]') === 1, 'Deletion submits outside the report form.');
                }
            }
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $element) $ids[] = $element->getAttribute('id');
        check(count($ids) === count(array_unique($ids)), 'Dialog fields and labels must have unique IDs.');
    }
}
echo "PASS: NL/EN inline documents, separate upload form ownership, empty/populated reports, image dialog and unique IDs.\n";
