<?php
declare(strict_types=1);

// Isolated SQLite and synthetic entities only: no kernel, credentials or network clients.
use App\Controller\ReportController;
use App\Entity\{Actor, ObjectRecord, Project, ProjectActor, ProjectObject, ProjectObjectActor, Report, ReportActor, ReportSeries};
use App\Service\{ProjectReportDefaults, ReportMeasurementDefaults, TmsReportSync};
use App\Value\ActorRole;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

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
$config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
$em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
$sync = new TmsReportSync($em, '8369', 'Koninklijk Museum voor Schone Kunsten Antwerpen');
$date = new DateTimeImmutable('2026-10-01');
$end = new DateTimeImmutable('2027-01-31');
$project = (new Project('Fixture loan', Project::TYPE_LOAN))->setStartDate($date)->setEndDate($end)->setDescription('Loan description');
$project->applyTmsSource('loans', '10', ['record' => ['LoanIn' => '1', 'Remarks' => 'Loan remarks']], new DateTimeImmutable());
$object = (new ObjectRecord('FIXTURE.1', 'Fixture work'))->setObjectType(ObjectRecord::TYPE_PAINTING);
$link = (new ProjectObject($project, $object))->setNotes('Object notes');
$link->applyTmsSource(['records' => [['Remarks' => 'Object remarks'], ['Remarks' => 'Object remarks']]]);
$series = new ReportSeries($object, '', $project);
foreach ([$project, $object, $link, $series] as $entity) $em->persist($entity);
$actors = [];
$roles = [ActorRole::OWNER_LENDER, ActorRole::OWNER_LENDER, ActorRole::OWNER_LENDER, ActorRole::OWNER_LENDER,
    ActorRole::OTHER, ActorRole::OTHER, ActorRole::COURIER, ActorRole::INSURER];
foreach ($roles as $i => $role) {
    $actor = (new Actor($i < 4 ? 'Fictional museum' : 'Fictional actor ' . $i, Actor::TYPE_UNKNOWN))->setAddress($i === 3 ? 'Different address' : 'Fixture address');
    $actor->applyTmsSource(hash('sha256', 'fixture:' . $i), ['record' => [
        'ConstituentID' => $i < 4 && $i !== 2 ? '11' : (string) (20 + $i), 'NameID' => (string) (100 + $i), 'UnMasked' => $i === 1 ? '0' : '1',
    ]]);
    $actors[] = $actor;
    $assignment = (new ProjectActor($project, $actor))->setRole($role)->setCustomRole($role === ActorRole::OTHER ? ($i === 4 ? 'schilder' : 'voormalig eigenaar') : null);
    $assignment->applyTmsSource((string) (601 + $i), $actor, ['record' => [], 'mapped' => [$role, null]]);
    $em->persist($actor); $em->persist($assignment);
}
$objectAssignment = (new ProjectObjectActor($link, $actors[1]))->setRole(ActorRole::OWNER_LENDER);
$objectAssignment->applyTmsSource('801', $actors[1], ['record' => []]);
$em->persist($objectAssignment);
$manual = new Actor('Manual conservator');
$em->persist($manual);
$draft = new Report($series);
$edited = (new Report($series))->setStartedAt(new DateTimeImmutable('2026-11-02'))->setDescription('Hand-written report description');
$final = new Report($series);
$archived = new Report($series);
foreach ([$draft, $edited, $final, $archived] as $report) {
    $em->persist($report);
    foreach ($actors as $i => $actor) $em->persist((new ReportActor($report, $actor))->setRole($roles[$i]));
    $em->persist((new ReportActor($report, $manual))->setRole(ActorRole::CONSERVATOR));
}
$final->finalize(null);
$archived->finalize(null)->archive();
$em->flush();
$draftId = $draft->getId(); $editedId = $edited->getId(); $finalId = $final->getId(); $archivedId = $archived->getId();
$projectId = $project->getId(); $seriesId = $series->getId();
$history = $em->getConnection()->fetchAllAssociative('SELECT * FROM reports WHERE id IN (?, ?)', [$finalId, $archivedId]);
$historyActors = $em->getConnection()->fetchAllAssociative('SELECT * FROM report_actors WHERE report_id IN (?, ?)', [$finalId, $archivedId]);
check($em->wrapInTransaction(fn() => $sync->synchronizeDrafts()) === 2, 'Both drafts should be updated.');
$draft = $em->find(Report::class, $draftId);
$edited = $em->find(Report::class, $editedId);
check($draft->getStartedAt() == $date && $draft->getEndedAt() == $end, 'Missing report dates did not use the loan period.');
check($draft->getReceiptAt() === null, 'Do not invent an actual receipt date.');
foreach (['Loan description', 'Loan remarks', 'Object notes', 'Object remarks'] as $text) {
    check(substr_count($draft->getDescription(), $text) === 1, 'Missing/duplicated report description text: ' . $text);
}
check($edited->getStartedAt()->format('Y-m-d') === '2026-11-02' && $edited->getEndedAt() == $end, 'Existing dates were overwritten or missing end date not filled.');
check($edited->getDescription() === 'Hand-written report description', 'Existing report description was overwritten.');
$assignments = $em->getRepository(ReportActor::class)->findBy(['report' => $draft]);
check(count($assignments) === 7, 'Expected three distinct lenders, courier, insurer, KMSKA and the manual conservator.');
check(count(array_filter($assignments, fn($a) => $a->getRole() === ActorRole::OTHER)) === 0, 'Unrelated imported actors remain.');
check(count(array_filter($assignments, fn($a) => $a->getRole() === ActorRole::CONSERVATOR)) === 1, 'Manual actor was deleted.');
// Inspect the explicit borrower role; the institution's address is deliberately not invented.
$own = $em->getRepository(ReportActor::class)->findOneBy(['report' => $draft, 'role' => ActorRole::BORROWER]);
$ownActorId = $own->getActor()->getId();
check($own->getActor()->getTmsData()['record']['ConstituentID'] === '8369' && $own->getActor()->getType() === Actor::TYPE_ORGANIZATION, 'Incoming loan did not select KMSKA as borrower.');
check($own->getActor()->getAddress() === null, 'Institution default must not invent an address.');
check($em->getConnection()->fetchAllAssociative('SELECT * FROM reports WHERE id IN (?, ?)', [$finalId, $archivedId]) === $history, 'Finalized or archived report was changed.');
check($em->getConnection()->fetchAllAssociative('SELECT * FROM report_actors WHERE report_id IN (?, ?)', [$finalId, $archivedId]) === $historyActors, 'Finalized/archived actor history was changed.');
check($em->wrapInTransaction(fn() => $sync->synchronizeDrafts()) === 0, 'Repeating draft sync must be a no-op.');

// Project screens use the same role restriction and source-aware deduplication.
$project = $em->find(Project::class, $projectId);
$visible = TmsReportSync::projectAssignments($em->getRepository(ProjectActor::class)->findBy(['project' => $project]));
check(count($visible) === 5, 'Project screen still shows duplicate or irrelevant imported actors.');

// Exercise actual new-report creation, including its default date and previous-report behavior.
$controller = (new ReflectionClass(ReportController::class))->newInstanceWithoutConstructor();
foreach (['entityManager' => $em, 'projectReportDefaults' => new ProjectReportDefaults(),
    'measurementDefaults' => new ReportMeasurementDefaults(), 'tmsReportSync' => $sync] as $property => $value) {
    (new ReflectionProperty(ReportController::class, $property))->setValue($controller, $value);
}
$container = new Container();
$container->set('security.token_storage', new TokenStorage());
$controller->setContainer($container);
$create = new ReflectionMethod($controller, 'createDraft');
$series = $em->find(ReportSeries::class, $seriesId);
$new = $em->wrapInTransaction(fn() => $create->invoke($controller, $series));
check($new->getStartedAt() == $date && $new->getEndedAt() == $end, 'New report kept today instead of the loan dates.');
check(count($em->getRepository(ReportActor::class)->findBy(['report' => $new])) === 7, 'New report did not get the filtered actor set.');
check(substr_count($new->getDescription(), 'Object remarks') === 1, 'New report duplicated remarks from a previous report.');
$second = $em->wrapInTransaction(fn() => $create->invoke($controller, $series));
check($second->getDescription() === $new->getDescription(), 'Repeated report creation keeps appending the same notes.');

// Outgoing direction must use the source KMSKA actor when available, never create a second assignment.
$project->applyTmsSource('loans', '10', ['record' => ['LoanIn' => '0', 'Remarks' => 'Loan remarks']], new DateTimeImmutable());
$outgoing = new Report($series);
$em->persist($outgoing);
$em->persist((new ReportActor($outgoing, $em->find(Actor::class, $ownActorId)))->setRole(ActorRole::BORROWER));
$em->flush();
check($sync->apply($outgoing, true), 'Outgoing loan defaults were not applied.');
$em->flush();
$ownAssignments = array_values(array_filter($em->getRepository(ReportActor::class)->findBy(['report' => $outgoing]),
    fn($a) => ($a->getActor()->getTmsData()['record']['ConstituentID'] ?? null) === '8369'));
check(count($ownAssignments) === 1 && $ownAssignments[0]->getRole() === ActorRole::OWNER_LENDER, 'KMSKA must be the outgoing lender exactly once.');

// No date/party guesses for unknown direction or manual projects; no notes leak from another project.
$otherProject = (new Project('Other project'))->setDescription('OTHER PROJECT NOTES');
$otherSeries = new ReportSeries($series->getObjectRecord(), '', $otherProject);
$previous = (new Report($otherSeries))->setDescription('OTHER PROJECT NOTES');
foreach ([$otherProject, $otherSeries, $previous] as $entity) $em->persist($entity);
$em->flush();
$next = $em->wrapInTransaction(fn() => $create->invoke($controller, $series));
check(!str_contains($next->getDescription(), 'OTHER PROJECT NOTES'), 'Description from another project leaked into the new loan report.');
check(!$sync->apply($previous, true) && $previous->getStartedAt() === null, 'Manual project report was changed.');
$project->applyTmsSource('loans', '10', ['record' => ['LoanIn' => null]], new DateTimeImmutable());
$unknown = new Report($series);
$em->persist($unknown); $em->flush();
$sync->apply($unknown, true); $em->flush();
check(count(array_filter($em->getRepository(ReportActor::class)->findBy(['report' => $unknown]),
    fn($a) => ($a->getActor()->getTmsData()['record']['ConstituentID'] ?? null) === '8369')) === 0, 'Unknown loan direction invented an institution role.');

// An explicit institution source arriving later must not duplicate the existing default assignment.
$project->applyTmsSource('loans', '10', ['record' => ['LoanIn' => '0']], new DateTimeImmutable());
$ownSource = (new Actor('Koninklijk Museum voor Schone Kunsten Antwerpen', Actor::TYPE_UNKNOWN))->setAddress('Fixture institution address');
$ownSource->applyTmsSource(hash('sha256', 'fixture-institution'), ['record' => ['ConstituentID' => '8369', 'UnMasked' => '1']]);
$sourceAssignment = (new ProjectActor($project, $ownSource))->setRole(ActorRole::OWNER_LENDER);
$sourceAssignment->applyTmsSource('999', $ownSource, ['record' => []]);
$em->persist($ownSource); $em->persist($sourceAssignment); $em->flush();
$sync->apply($outgoing); $em->flush();
check(count(array_filter($em->getRepository(ReportActor::class)->findBy(['report' => $outgoing]),
    fn($a) => ($a->getActor()->getTmsData()['record']['ConstituentID'] ?? null) === '8369')) === 1, 'A later institution source duplicated the default KMSKA assignment.');

// The import transaction also rolls draft changes back if later work fails.
$rollback = new Report($series);
$em->persist($rollback); $em->flush();
$rollbackId = $rollback->getId();
$before = $em->getConnection()->fetchAssociative('SELECT * FROM reports WHERE id = ?', [$rollbackId]);
try {
    $em->wrapInTransaction(function () use ($sync, $em, $rollback): void {
        $sync->apply($rollback); $em->flush();
        throw new RuntimeException('Synthetic failure after report update');
    });
    throw new LogicException('Expected transaction failure');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Synthetic failure after report update', 'Unexpected rollback error.');
}
check($em->getConnection()->fetchAssociative('SELECT * FROM reports WHERE id = ?', [$rollbackId]) === $before, 'Failed import left draft fields changed.');
check((int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM report_actors WHERE report_id = ?', [$rollbackId]) === 0, 'Failed import left report actor assignments.');

echo "OK: {$checks} checks; loan dates, notes, roles, duplicates, KMSKA, draft backfill, history and actual report creation; isolated SQLite only.\n";
