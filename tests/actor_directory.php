<?php

declare(strict_types=1);

// Isolated SQLite and synthetic templates only. No kernel, credentials or network.
use App\Entity\{Actor, ObjectRecord, Project, ProjectActor, Report, ReportActor, ReportSeries};
use App\Service\ActorDirectory;
use App\Value\Pagination;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
$directory = new ActorDirectory($em);
check($directory->page()['groups'] === [], 'The empty directory must remain empty.');

$actors = [];
foreach ([
    ['Museum <Alpha>', '00042', 'Teststraat 1', '1'],
    ['Museum <Alpha>', '42', 'Teststraat 2', '1'],
    ['Museum <Alpha>', '42', 'Teststraat 1', '1'],
    ['Private collection', '42', null, '0'],
    ['Museum <Alpha>', '43', 'Teststraat 1', '1'],
    ['Unknown source', null, null, '1'],
    ['Unknown source', null, null, '1'],
] as $i => [$name, $constituent, $address, $visibility]) {
    $actor = (new Actor($name, Actor::TYPE_UNKNOWN))->setAddress($address)->setCity('Teststad');
    $actor->applyTmsSource(hash('sha256', 'fixture-' . $i), ['record' => [
        'ConstituentID' => $constituent, 'NameID' => (string) (100 + $i),
        'AddressID' => (string) (200 + $i), 'UnMasked' => $visibility,
    ]]);
    $actors[] = $actor;
}
$actors[] = new Actor('Museum <Alpha>'); // A local namesake is not a TMS identity.
$project = new Project('Fixture project');
$object = new ObjectRecord('FIXTURE-1', 'Fixture object');
$series = new ReportSeries($object, '', $project);
$report = new Report($series);
$report->finalize(1);
foreach ([...$actors, $project, $object, $series, $report,
    new ReportActor($report, $actors[1]), new ProjectActor($project, $actors[2])] as $entity) $em->persist($entity);
$em->flush();
$ids = array_map(static fn (Actor $actor) => $actor->getId(), $actors);
$em->clear();
$connection = $em->getConnection();
$snapshot = static fn () => array_map(static fn ($table) => $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'),
    ['actors', 'project_actors', 'report_actors', 'reports']);
$before = $snapshot();
$result = $directory->page();
$groups = $result['groups'];
check(count($groups) === 5, 'Group only known TMS identities; keep local actors and unknown identities separate.');
$variants = array_values(array_filter($groups, static fn ($group) => $group['actor']['constituentId'] === '42'))[0]['variants'];
check(count($variants) === 4, 'All name, address and masked variants must remain accessible under the same TMS actor.');
check(count(array_filter($groups, static fn ($group) => $group['actor']['constituentId'] === '43')) === 1,
    'Different TMS actors with identical names must not merge.');
check($before === $snapshot(), 'Displaying groups must not modify actors, projects or finalized reports.');
check($em->getUnitOfWork()->size() === 0, 'Do not retain managed entities or large source payloads for the directory.');
check(!array_key_exists('tmsData', $variants[0]), 'Discard source JSON after reading its identity.');
check($groups === $directory->page()['groups'], 'The directory ordering must be stable.');

$templates = ['base.html.twig' => '{% block body %}{% endblock %}'];
$templates['shared/_pagination.html.twig'] = file_get_contents($root . '/templates/shared/_pagination.html.twig');
foreach (['index', '_row'] as $name) $templates['admin/actors/' . $name . '.html.twig'] = file_get_contents($root . '/templates/admin/actors/' . $name . '.html.twig');
$translator = new Translator('nl');
$translator->addLoader('array', new ArrayLoader());
$twig = new Environment(new TwigArrayLoader($templates), ['strict_variables' => true, 'autoescape' => 'html']);
$twig->addFilter(new TwigFilter('trans', $translator->trans(...)));
$twig->addFunction(new TwigFunction('path', static fn ($route, $params) => '/' . $route . '/' . ($params['id'] ?? '')));
$twig->addFunction(new TwigFunction('csrf_token', static fn ($value) => 'fixture-' . $value));
foreach (['nl', 'en'] as $locale) {
    $translator->addResource('array', Yaml::parse(file_get_contents($root . '/translations/messages.' . $locale . '.yaml')), $locale);
    $translator->setLocale($locale);
    $html = $twig->render('admin/actors/index.html.twig', ['actor_groups' => $groups, 'pagination' => $result['pagination'], 'q' => '', 'app' => ['request' => ['locale' => $locale]]]);
    check(substr_count($html, '<details class="actor-variants">') === 1, 'Only the actor with multiple variants needs an expandable group.');
    check(str_contains($html, 'Teststraat 1') && str_contains($html, 'Teststraat 2') && str_contains($html, 'Private collection'), 'Keep all variants and their distinguishing addresses visible when expanded.');
    check(str_contains($html, 'Museum &lt;Alpha&gt;') && !str_contains($html, 'Museum <Alpha>'), 'Escape source names.');
    foreach ($ids as $id) {
        check(substr_count($html, 'href="/admin_actors_edit/' . $id . '"') === 1, 'Every original variant must remain individually editable.');
        check(str_contains($html, 'value="fixture-admin_actor_delete_' . $id . '"'), 'Keep deletion scoped to the original actor and CSRF token.');
    }
    check(!str_contains($html, 'actors.grouped_variants_hint') && !str_contains($html, 'actors.variants_count'), 'Translate the grouped directory.');
    $empty = $twig->render('admin/actors/index.html.twig', ['actor_groups' => [], 'pagination' => new Pagination(0), 'q' => '', 'app' => ['request' => ['locale' => $locale]]]);
    check(!str_contains($empty, '<table'), 'Keep the empty directory state.');
}

// Filter across all variants, then paginate complete groups rather than individual actor rows.
check($directory->page('ALPHA')['pagination']->total === 3, 'Name matching must ignore case while keeping different identities separate.');
$filtered = $directory->page('private collection', 999);
check($filtered['pagination']->total === 1 && $filtered['pagination']->page === 1
    && count($filtered['groups'][0]['variants']) === 4, 'A matching secondary name must return the entire group and clamp its page.');
check($directory->page('%_')['groups'] === [], 'Treat filter wildcards as literal name characters.');
for ($i = 1; $i <= 105; ++$i) {
    $actor = new Actor(sprintf('ZZZ fixture %03d', $i));
    if ($i === 100) $actor->setAlias('ZZZ spéciale alias');
    $em->persist($actor);
}
$em->flush();
$em->clear();
$seen = [];
for ($page = 1; $page <= 3; ++$page) {
    $paged = $directory->page('', $page);
    check($paged['pagination']->total === 110 && $paged['pagination']->pages === 3, 'Count groups, not source variants.');
    check(count($paged['groups']) === ($page < 3 ? 50 : 10), 'Return exactly one page of actor groups.');
    foreach ($paged['groups'] as $group) {
        $id = $group['actor']['id'];
        check(!isset($seen[$id]), 'An actor group appeared on multiple pages.');
        $seen[$id] = true;
        if ($group['actor']['constituentId'] === '42') check(count($group['variants']) === 4, 'Pagination split an actor group.');
    }
}
check(count($seen) === 110, 'All actor groups must be reachable by pagination.');
check($directory->page('SPÉCIALE')['pagination']->total === 1, 'Search aliases case-insensitively, including accented characters.');
check($directory->page('fixture 090')['pagination']->total === 1, 'Find names beyond the first page.');
check($directory->page('', -10)['pagination']->page === 1 && $directory->page('', PHP_INT_MAX)['pagination']->page === 3,
    'Clamp invalid page bounds without overflowing offsets.');
$missing = $directory->page('no-such-actor', 99);
check($missing['pagination']->total === 0 && $missing['pagination']->first === 0 && $missing['groups'] === [], 'Handle an empty filtered directory.');
check($em->getUnitOfWork()->size() === 0, 'Pagination must not hydrate every source actor into Doctrine.');
$em->close();
echo "OK: {$checks} checks; grouped actor directory, isolated SQLite and NL/EN templates.\n";
