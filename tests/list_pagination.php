<?php

declare(strict_types=1);

// Isolated SQLite, real list controllers and Twig fixtures; no kernel, credentials or network.
use App\Controller\{AdminActorController, ProjectController};
use App\Entity\{Actor, ObjectRecord, Project, ProjectObject, Report, ReportSeries};
use App\Service\ActorDirectory;
use App\Value\Pagination;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\{Loader\ArrayLoader, Translator};
use Symfony\Component\Yaml\Yaml;
use Twig\{Environment, Loader\ArrayLoader as TwigArrayLoader, TwigFilter, TwigFunction};

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) $loader->addPsr4($prefix, $paths);
$loader->register();
$checks = 0;
function check(bool $ok, string $message): void { global $checks; ++$checks; if (!$ok) throw new RuntimeException($message); }
$config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, null, new ArrayAdapter());
$config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
$projects = [];
for ($i = 1; $i <= 125; ++$i) {
    $project = (new Project(sprintf('Fixture %03d', $i)))->setReferenceCode('REF-' . $i)->setDescription($i === 1 ? 'Only in description' : null);
    // Imports often give thousands of projects exactly the same timestamp.
    (new ReflectionProperty(Project::class, 'updatedAt'))->setValue($project, new DateTimeImmutable('2026-01-01'));
    $em->persist($project);
    $projects[] = $project;
}
$object = new ObjectRecord('FIXTURE-1', 'Fixture work');
$link = new ProjectObject($projects[0], $object);
$series = new ReportSeries($object, '', $projects[0]);
$report = new Report($series);
$report->finalize(1);
foreach ([$object, $link, $series, $report, new Actor('Unique <actor>')] as $entity) $em->persist($entity);
$em->flush();
$ids = array_map(static fn (Project $project) => $project->getId(), array_reverse($projects));

$templates = ['base.html.twig' => '{% block javascripts %}{% endblock %}{% block body %}{% endblock %}'];
foreach (['projects/index', 'admin/actors/index', 'admin/actors/_row', 'shared/_pagination'] as $name) {
    $templates[$name . '.html.twig'] = file_get_contents($root . '/templates/' . $name . '.html.twig');
}
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
foreach (['nl', 'en'] as $locale) $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
$twig = new Environment(new TwigArrayLoader($templates), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addGlobal('app', ['request' => ['locale' => 'nl']]);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFunction(new TwigFunction('path', static function ($route, $params): string {
    $path = '/' . $params['_locale'] . '/' . $route;
    unset($params['_locale']);
    if (isset($params['id'])) { $path .= '/' . $params['id']; unset($params['id']); }
    return $path . ($params ? '?' . http_build_query($params) : '');
}));
$twig->addFunction(new TwigFunction('csrf_token', static fn ($value) => 'fixture-' . $value));
$container = new Container();
$container->set('twig', $twig);
// The index only uses the EntityManager; do not construct unrelated image/network services.
$controller = (new ReflectionClass(ProjectController::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(ProjectController::class, 'entityManager'))->setValue($controller, $em);
$controller->setContainer($container);
$render = static fn (array $query): string => $controller->index(new Request($query))->getContent();
$pageIds = static function (string $html): array {
    preg_match_all('~data-row-href="/nl/projects_edit/(\d+)"~', $html, $matches);
    return array_map('intval', $matches[1]);
};
$seen = [];
for ($page = 1; $page <= 3; ++$page) {
    $html = $render(['page' => $page]);
    $actual = $pageIds($html);
    check($actual === array_slice($ids, ($page - 1) * 50, 50), 'Page ordering must be stable when project timestamps are identical.');
    check(str_contains($html, 'aria-current="page">' . $page), 'Mark the current page accessibly.');
    $seen = [...$seen, ...$actual];
}
check($seen === $ids && count(array_unique($seen)) === 125, 'Every project, including those beyond the old limit of 100, must be reachable exactly once.');
check($pageIds($render(['page' => -10])) === array_slice($ids, 0, 50), 'Clamp negative page requests.');
check($pageIds($render(['page' => PHP_INT_MAX])) === array_slice($ids, 100, 50), 'Clamp oversized page requests before computing offsets.');
check($pageIds($render(['q' => 'REF-1', 'page' => 99])) === array_values(array_filter($ids, static fn ($id) => str_starts_with('REF-' . $id, 'REF-1'))), 'Search references across the entire project collection and clamp the filtered page.');
$html = $render(['q' => 'Only in description']);
check($pageIds($html) === [$projects[0]->getId()] && substr_count($html, 'class="table-count">') === 2,
    'Preserve description search and project object/report counts.');
check(str_contains($html, $translator->trans('reports.finalized_count', ['%count%' => 1])), 'Preserve finalized report counts on filtered pages.');
$html = $render(['q' => 'Fixture', 'page' => 2]);
check(str_contains($html, 'q=Fixture&amp;page=3') && str_contains($html, 'q=Fixture&amp;page=1'), 'Keep the active filter in next/previous page links.');
check(!str_contains($render(['q' => '<no-such-project>']), '<table'), 'An unmatched filter must not show an unrelated page.');
check(!str_contains($render(['q' => '<no-such-project>']), 'value="<no-such-project>"'), 'Escape the search term.');

$actorController = new AdminActorController($em);
$actorController->setContainer($container);
$actorHtml = $actorController->index(new Request(['q' => 'Unique', 'page' => 999]), new ActorDirectory($em))->getContent();
check(str_contains($actorHtml, 'Unique &lt;actor&gt;') && str_contains($actorHtml, 'data-list-filter')
    && str_contains($actorHtml, 'data-list-results') && str_contains($actorHtml, '/js/list-filter.js?v=1'), 'Wire the actor filter and paginated response into its real controller.');
foreach (['nl', 'en'] as $locale) {
    $translator->setLocale($locale);
    $html = $twig->render('shared/_pagination.html.twig', ['pagination' => new Pagination(10000, 100),
        'pagination_route' => 'admin_actors_index', 'pagination_params' => ['_locale' => $locale, 'q' => 'Name & alias']]);
    check(str_contains($html, 'page=1"') && str_contains($html, 'page=200"'), 'Keep the first and last page reachable on a long list.');
    check(substr_count($html, '<a ') <= 8 && str_contains($html, 'aria-current="page">100'), 'Do not render hundreds of page links.');
    check(str_contains($html, 'q=Name+%26+alias&amp;page='), 'URL-encode filters without losing their content.');
    check(!str_contains($html, 'pagination.'), 'Translate pagination in both languages.');
}
$em->close();
echo "OK: {$checks} checks; list pagination, real controllers, SQLite and NL/EN templates.\n";
