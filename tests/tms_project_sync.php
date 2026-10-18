<?php
declare(strict_types=1);

// Isolated SQLite, fake HTTP arrays, and a unique workspace temp directory only.
// Deliberately bypass vendor/autoload.php, kernel, .env and any native HTTP transport.
use App\Command\SyncTmsProjectsCommand;
use App\Entity\{Actor, ProjectActor, ProjectObjectActor, ReportActor, ObjectLogEntry, ObjectRecord, Project, ProjectObject, Report, ReportSeries};
use App\Controller\ReportController;
use App\Value\ActorRole;
use App\Service\{ObjectChangeLogger, TmsApiClient, TmsProjectSync, TmsActorSync};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Output\OutputInterface;

$root = dirname(__DIR__);
require $root . '/vendor/composer/ClassLoader.php';
require $root . '/vendor/symfony/deprecation-contracts/function.php';
$loader = new Composer\Autoload\ClassLoader();
foreach (require $root . '/vendor/composer/autoload_psr4.php' as $prefix => $paths) {
    $loader->addPsr4($prefix, $paths);
}
$loader->register();

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    ++$checks;
    if (!$ok) throw new RuntimeException($message);
}

function manager(): EntityManager
{
    global $root;
    $config = ORMSetup::createAttributeMetadataConfiguration([$root . '/src/Entity'], true, null, new ArrayAdapter());
    $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
    $config->setAutoGenerateProxyClasses(Doctrine\ORM\Proxy\ProxyFactory::AUTOGENERATE_EVAL);
    $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    return $em;
}

function datasets(): array
{
    return [
        'exhibitions' => [['ExhibitionID' => '10', 'ProjectNumber' => 'EX-01', 'ExhTitle' => 'Test exhibition', 'DisplayDate' => 'voorjaar 2027']],
        'loans' => [['LoanID' => '10', 'LoanNumber' => 'LN-01', 'LoanIn' => '1', 'Description' => 'Test loan',
            'BeginISODate' => '20270105', 'EndISODate' => '2027-02-06', 'LoanStatus' => 'Closed']],
        'objects' => [
            ['ObjectID' => '100', 'ObjectNumber' => '0001', 'ObjectName' => 'TMS object', 'Dated' => '1917'],
            ['ObjectID' => '101', 'ObjectNumber' => 'TEMP.001', 'ObjectName' => 'Incoming work', 'Dated' => 'ca. 1920'],
        ],
        'object_titles' => [
            ['TitleID' => '201', 'ObjectID' => '100', 'Title' => 'Do not replace DataHub', 'Displayed' => '1', 'Active' => '1', 'LanguageID' => '2', 'DisplayOrder' => '1'],
            ['TitleID' => '202', 'ObjectID' => '101', 'Title' => 'Incoming title', 'Displayed' => '1', 'Active' => '1', 'LanguageID' => '2', 'DisplayOrder' => '1'],
            ['TitleID' => '203', 'ObjectID' => '101', 'Title' => 'English title', 'Displayed' => '1', 'Active' => '1', 'LanguageID' => '1', 'DisplayOrder' => '1'],
        ],
        'loan_objects' => [['LoanObjXrefID' => '301', 'LoanID' => '10', 'ObjectID' => '101', 'SpecialConditions' => 'Test condition'],
            ['LoanObjXrefID' => '302', 'LoanID' => '10', 'ObjectID' => '101', 'SpecialConditions' => 'Second source row']],
        'exhibition_objects' => [['ExhObjXrefID' => '401', 'ExhibitionID' => '10', 'ObjectID' => '100']],
        'exhibition_loans' => [['ExhibitionID' => '10', 'LoanID' => '10']],
        'shipments' => [['LoanID' => '10', 'ShipmentID' => '501', 'ShipmentNumber' => 'SHIP-01']],
        'parties' => [party()],
        'documents' => [['MediaXrefID' => '701', 'TableID' => '81', 'ID' => '10', 'PrimaryFileName' => 'fake.pdf']],
    ];
}

function syncer(EntityManager $em, array &$state, string $directory, bool $enabled = true): TmsProjectSync
{
    $transport = static function (string $url, array $headers) use (&$state): array {
        ++$state['calls'];
        if ($state['invalid_json'] ?? false) return ['status' => 200, 'body' => 'SECRET_DO_NOT_PRINT'];
        if ($state['internal_failure'] ?? false) throw new RuntimeException('SECRET_DO_NOT_PRINT');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $manifest = ['schema_version' => 2,
            'scope' => ['exhibition_ids' => $state['scope'] ?? null],
            'datasets' => array_fill_keys(array_keys($state['data']), ['enabled' => true])];
        foreach ($state['disabled'] ?? [] as $disabled) $manifest['datasets'][$disabled]['enabled'] = false;
        if ($query === []) {
            ++$state['manifests'];
            if (($state['change_at_end'] ?? false) && $state['manifests'] % 2 === 0) {
                $manifest['datasets']['parties']['enabled'] = false;
            }
            return ['status' => 200, 'body' => json_encode($manifest, JSON_THROW_ON_ERROR)];
        }
        check(in_array('Authorization: Basic ' . base64_encode('test:FAKE-password'), $headers, true), 'Basic auth is required.');
        $dataset = $query['dataset'];
        check(!in_array($dataset, $state['disabled'] ?? [], true), 'Disabled dataset was requested.');
        if ($dataset === ($state['fail_dataset'] ?? '') && (int) $query['offset'] >= ($state['fail_offset'] ?? 0)) {
            return ['status' => $state['failure_status'] ?? 503, 'body' => 'SECRET_DO_NOT_PRINT'];
        }
        $offset = (int) $query['offset'];
        $limit = (int) $query['limit'];
        $page = array_slice($state['data'][$dataset], $offset, $limit);
        $response = $manifest + ['dataset' => $dataset, 'offset' => $offset, 'limit' => $limit, 'count' => count($page),
            'next_offset' => count($state['data'][$dataset]) > $offset + $limit ? $offset + $limit : null, 'data' => $page];
        if ($state['bad_limit'] ?? false) $response['limit'] = 999;
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    };
    $client = new TmsApiClient($enabled, 'https://fixtures.invalid/loan_projects.php', 'test', 'FAKE-password', $transport);
    return new TmsProjectSync($client, $em, new ObjectChangeLogger($em), $directory, new TmsActorSync($em));
}

function party(array $changes = []): array
{
    return array_replace(['ConXrefDetailID' => '601', 'TableID' => '81', 'ID' => '10',
        'ConstituentID' => '801', 'NameID' => '901', 'AddressID' => '1001', 'UnMasked' => '1',
        'DisplayName' => 'Fictional lender', 'Role' => 'Bruikleengever', 'RoleTypeID' => '17',
        'Active' => '1', 'Displayed' => '1', 'AddressActive' => '1', 'StreetLine1' => 'Teststraat 1',
        'StreetLine2' => null, 'StreetLine3' => null, 'ZipCode' => '0001', 'City' => 'Teststad',
        'State' => null, 'Country' => 'Testland'], $changes);
}

function state(): array { return ['data' => datasets(), 'calls' => 0, 'manifests' => 0]; }

$directory = $root . '/tmp/tms-sync-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
try {
    $em = manager();
    $datahub = (new ObjectRecord('0001', 'DataHub title'))->applyDatahubSync('0001', 'lido:original', ['title_nl' => 'DataHub title', 'custom' => 'preserve']);
    $datahub->setImageUrl('/uploads/fixture.jpg')->setCreator('DataHub artist');
    $manual = (new Project('Local project', Project::TYPE_LOAN))->setReferenceCode('LN-01');
    $em->persist($datahub);
    $em->persist($manual);
    $em->flush();
    $datahubId = $datahub->getId();
    $manualId = $manual->getId();
    $fixture = state();
    $sync = syncer($em, $fixture, $directory);
    $first = $sync->synchronize();
    check($first['projects_created'] === 2 && $first['objects_created'] === 1 && $first['links'] === 2, 'Initial import counts are incorrect.');
    $em->clear();
    $loan = $em->getRepository(Project::class)->findOneBy(['tmsType' => 'loans', 'tmsId' => '10']);
    $exhibition = $em->getRepository(Project::class)->findOneBy(['tmsType' => 'exhibitions', 'tmsId' => '10']);
    $object = $em->getRepository(ObjectRecord::class)->findOneBy(['tmsId' => '101']);
    $datahub = $em->find(ObjectRecord::class, $datahubId);
    check($loan !== null && $exhibition !== null && $loan->getId() !== $exhibition->getId(), 'Loan and exhibition ID spaces must remain separate.');
    check($loan->getStartDate()?->format('Y-m-d') === '2027-01-05' && $loan->getEndDate()?->format('Y-m-d') === '2027-02-06', 'Full ISO dates were not imported.');
    check($loan->getStatus() === Project::STATUS_ACTIVE && $loan->getTmsData()['record']['LoanStatus'] === 'Closed', 'Do not guess workflow status from source labels.');
    check($exhibition->getStartDate() === null && $exhibition->getTmsData()['record']['DisplayDate'] === 'voorjaar 2027', 'Do not parse a display date into an invented day.');
    check($exhibition->getTmsData()['loan_ids'] === ['10'] && $loan->getTmsData()['exhibition_ids'] === ['10'], 'Preserve exhibition/loan links.');
    check($loan->getTmsData()['parties'][0]['DisplayName'] === 'Fictional lender' && count($loan->getTmsData()['documents']) === 1 && count($loan->getTmsData()['shipments']) === 1, 'Related source metadata was lost.');
    check($datahub->getTmsId() === '100' && $datahub->getTitle() === 'DataHub title' && $datahub->getExternalId() === 'lido:original', 'Existing DataHub identity/content changed.');
    check($datahub->getImageUrl() === '/uploads/fixture.jpg' && $datahub->getCreator() === 'DataHub artist' && $datahub->getSourceData()['custom'] === 'preserve', 'DataHub image/source fields changed.');
    check($object->getTitle() === 'Incoming title' && $object->getSource() === ObjectRecord::SOURCE_TMS, 'Create missing incoming objects with a suitable title.');
    check($em->find(Project::class, $manualId)->getTmsId() === null, 'Never claim a local project merely because its reference matches.');
    $link = $em->getRepository(ProjectObject::class)->findOneBy(['project' => $loan]);
    check(count($link->getTmsData()['records']) === 2, 'Multiple source links must not create duplicate project objects or discard conditions.');

    $series = new ReportSeries($object, 'Historical report', $loan);
    $report = new Report($series);
    $em->persist($series);
    $em->persist($report);
    $loan->setTitle('Local override')->setStatus(Project::STATUS_COMPLETED)->setEnvironmentalConditions(['temperature' => '20 C']);
    $link->setNotes('Keep local notes')->setRoom('Room 4')->setStatus(ProjectObject::STATUS_COMPLETED);
    $manualLink = new ProjectObject($loan, $datahub);
    $em->persist($manualLink);
    $em->flush();
    $loanId = $loan->getId(); $objectId = $object->getId(); $linkId = $link->getId(); $reportId = $report->getId();
    $fixture['data']['loans'][0]['Description'] = 'Changed upstream';
    $fixture['data']['loans'][0]['LoanNumber'] = 'LN-02';
    $fixture['data']['object_titles'][1]['Title'] = 'Changed incoming title';
    $second = $sync->synchronize();
    check($second['projects_created'] === 0 && $second['objects_created'] === 0, 'Repeated imports must not duplicate records.');
    $em->clear();
    $loan = $em->find(Project::class, $loanId);
    $object = $em->find(ObjectRecord::class, $objectId);
    check($loan->getTitle() === 'Local override' && $loan->getReferenceCode() === 'LN-02' && $loan->getDescription() === 'Changed upstream', 'Preserve overrides while refreshing unchanged source-owned fields.');
    check($loan->getStatus() === Project::STATUS_COMPLETED && $loan->getEnvironmentalCondition('temperature') === '20 C', 'Preserve local workflow and conditions.');
    check($object->getTitle() === 'Changed incoming title' && $object->getSyncStatus() === ObjectRecord::SYNC_SYNCED, 'Refresh TMS object without treating the sync as a manual edit.');
    check($em->find(ProjectObject::class, $linkId)->getNotes() === 'Keep local notes', 'Preserve link notes.');
    check($em->getRepository(Report::class)->count([]) === 1 && $em->find(Report::class, $reportId) !== null, 'Reports must survive repeated sync.');

    $object->setTitle('Local object title');
    $em->flush();
    $fixture['data']['object_titles'][1]['Title'] = 'Third source title';
    $fixture['disabled'] = ['parties', 'documents'];
    $sync->synchronize();
    $em->clear();
    check($em->find(ObjectRecord::class, $objectId)->getTitle() === 'Local object title', 'Preserve locally edited TMS object title.');
    check($em->find(Project::class, $loanId)->getTmsData()['parties'] === [], 'Remove stale optional metadata when its API dataset is disabled.');

    // An incoming object can later be harvested by DataHub without losing the TMS association.
    $object = $em->find(ObjectRecord::class, $objectId);
    $object->applyDatahubSync('TEMP.001', 'new-lido', ['title_nl' => 'New DataHub title']);
    $em->flush();
    $sync->synchronize();
    $em->clear();
    check($em->find(ObjectRecord::class, $objectId)->getTitle() === 'New DataHub title'
        && $em->find(ObjectRecord::class, $objectId)->getTmsId() === '101', 'DataHub and TMS import must coexist.');

    $fixture['data'] = array_fill_keys(array_keys(datasets()), []);
    $gone = $sync->synchronize();
    $em->clear();
    check($gone['projects_missing'] === 2 && $gone['links_missing'] === 2, 'Missing source records must be marked.');
    check(!$em->find(Project::class, $loanId)->isTmsPresent() && $em->find(ProjectObject::class, $linkId)->isTmsPresent() === false, 'Missing presence flags were not persisted.');
    check($em->find(ProjectObject::class, $manualLink->getId())->isTmsPresent() === null, 'Manual link must stay manual.');
    check($em->find(Report::class, $reportId) !== null && $em->getRepository(ObjectRecord::class)->count([]) === 2, 'Never delete reports or objects absent from the source.');
    $fixture['data'] = datasets();
    $sync->synchronize();
    $em->clear();
    check($em->find(Project::class, $loanId)->isTmsPresent() && $em->find(ProjectObject::class, $linkId)->isTmsPresent() === true, 'Returning source records must reuse existing identities.');

    // The disabled flag and overlapping runs cannot trigger HTTP or database work.
    $idle = state();
    $disabled = syncer($em, $idle, $directory . '/must-not-exist', false);
    $command = new CommandTester(new SyncTmsProjectsCommand($disabled));
    check($command->execute([]) === 0 && $idle['calls'] === 0 && !is_dir($directory . '/must-not-exist'), 'Disabled sync must be a true no-op.');
    $held = fopen($directory . '/run.lock', 'c');
    flock($held, LOCK_EX | LOCK_NB);
    $calls = $fixture['calls'];
    check($sync->synchronize()['status'] === 'locked' && $fixture['calls'] === $calls, 'Concurrent run was not blocked before HTTP.');
    fclose($held);

    // HTTP failure, manifest configuration change, limited scope and malformed data never leave partial changes.
    foreach (['failure', 'invalid_json', 'internal_failure', 'changed', 'scope', 'orphan', 'duplicate', 'collision', 'bad_limit', 'missing_column', 'missing_dataset'] as $scenario) {
        $badEm = manager();
        $old = new Project('Previously imported', Project::TYPE_LOAN);
        $old->applyTmsSource('loans', '99', ['record' => [], 'mapped' => []], new DateTimeImmutable('2020-01-01'));
        $badEm->persist($old);
        if ($scenario === 'collision') {
            $existing = new ObjectRecord('TEMP.001', 'Unrelated object');
            $existing->applyTmsSource('999', [], new DateTimeImmutable());
            $badEm->persist($existing);
        }
        $badEm->flush();
        $beforeProjects = $badEm->getConnection()->fetchAllAssociative('SELECT * FROM projects');
        $beforeObjects = $badEm->getConnection()->fetchAllAssociative('SELECT * FROM object_records');
        $bad = state();
        if ($scenario === 'failure') $bad['fail_dataset'] = 'documents';
        if ($scenario === 'invalid_json') $bad['invalid_json'] = true;
        if ($scenario === 'internal_failure') $bad['internal_failure'] = true;
        if ($scenario === 'changed') $bad['change_at_end'] = true;
        if ($scenario === 'scope') $bad['scope'] = ['10'];
        if ($scenario === 'orphan') $bad['data']['loan_objects'][0]['ObjectID'] = '999';
        if ($scenario === 'duplicate') $bad['data']['loans'][] = $bad['data']['loans'][0];
        if ($scenario === 'bad_limit') $bad['bad_limit'] = true;
        if ($scenario === 'missing_column') unset($bad['data']['loans'][0]['LoanNumber']);
        if ($scenario === 'missing_dataset') unset($bad['data']['exhibitions']);
        $test = new CommandTester(new SyncTmsProjectsCommand(syncer($badEm, $bad, $directory)));
        check($test->execute([]) === 1, 'Invalid import was accepted: ' . $scenario);
        if ($scenario === 'failure') check(str_contains($test->getDisplay(), 'HTTP 503 (dataset documents, offset 0)'), 'HTTP diagnostic lost request context.');
        if ($scenario === 'invalid_json') check(str_contains($test->getDisplay(), 'invalid JSON (manifest)'), 'API diagnostic was hidden by the command.');
        check(!str_contains($test->getDisplay(), 'SECRET_DO_NOT_PRINT') && !str_contains($test->getDisplay(), 'FAKE-password'), 'Failure output leaked raw data.');
        check($badEm->getConnection()->fetchAllAssociative('SELECT * FROM projects') === $beforeProjects, 'Failure changed projects: ' . $scenario);
        check($badEm->getConnection()->fetchAllAssociative('SELECT * FROM object_records') === $beforeObjects, 'Failure changed objects: ' . $scenario);
        check($badEm->getConnection()->fetchOne('SELECT COUNT(*) FROM object_log_entries') === 0, 'Rolled-back sync left audit entries.');
        check(glob($directory . '/download-*') === [], 'Temporary download leaked after failure.');
        $badEm->close();
    }

    // Only explicit verbosity prints response previews; it must preserve the same rollback behavior.
    $verboseEm = manager();
    $verbose = state();
    $verbose['fail_dataset'] = 'objects';
    $verboseCommand = new CommandTester(new SyncTmsProjectsCommand(syncer($verboseEm, $verbose, $directory)));
    check($verboseCommand->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]) === 1, 'Verbose API failure must fail the command.');
    $display = $verboseCommand->getDisplay();
    check(str_contains($display, 'GET https://fixtures.invalid/loan_projects.php?dataset=objects&offset=0&limit=500')
        && str_contains($display, 'Respons: HTTP 503') && str_contains($display, 'Responsbody'), 'Command did not forward verbose request/response diagnostics.');
    check(str_contains($display, 'SECRET_DO_NOT_PRINT'), 'Explicit verbose output must include the fake response body.');
    check(!str_contains($display, 'FAKE-password'), 'Command exposed the Basic password.');
    check($verboseEm->getRepository(Project::class)->count([]) === 0, 'Verbose failure stored a partial import.');
    $verbose['fail_dataset'] = '';
    check($verboseCommand->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]) === 0, 'Verbose successful import failed.');
    check(str_contains($verboseCommand->getDisplay(), 'databasetransactie starten'), 'Missing transition from API download to database import.');
    $verboseEm->close();

    // Real pagination: the final page is needed; failure on that page cannot publish the first 500 rows.
    $paged = state();
    $paged['data']['exhibitions'] = [];
    for ($i = 1; $i <= 501; ++$i) {
        $paged['data']['exhibitions'][] = ['ExhibitionID' => (string) $i, 'ExhTitle' => 'Fixture ' . $i, 'ProjectNumber' => null, 'DisplayDate' => null];
    }
    $pagedEm = manager();
    $pagedSync = syncer($pagedEm, $paged, $directory);
    $pagedResult = $pagedSync->synchronize();
    check($pagedResult['projects_created'] === 502, 'Did not consume the last API page.');
    $paged['fail_dataset'] = 'exhibitions'; $paged['fail_offset'] = 500;
    $paged['data']['exhibitions'][0]['ExhTitle'] = 'Must not publish';
    $pagedCommand = new CommandTester(new SyncTmsProjectsCommand($pagedSync));
    check($pagedCommand->execute([]) === 1, 'Failure on page 2 must fail the command.');
    check(str_contains($pagedCommand->getDisplay(), 'dataset exhibitions, offset 500'), 'Failure did not identify page 2.');
    check($pagedEm->getConnection()->fetchOne("SELECT title FROM projects WHERE tms_type = 'exhibitions' AND tms_id = '1'") === 'Fixture 1', 'Page 1 escaped before page 2 succeeded.');
    check(glob($directory . '/download-*') === [], 'Temporary download files remain.');
    // Actors are usable assignments, not just JSON metadata. Keep identities and visibility variants apart.
    $actorEm = manager();
    $localActor = new Actor('Fictional lender');
    $actorEm->persist($localActor);
    $actorEm->flush();
    $localActorId = $localActor->getId();
    $actors = state();
    $actors['data']['exhibition_objects'][] = ['ExhObjXrefID' => '402', 'ExhibitionID' => '10', 'ObjectID' => '101'];
    $actors['data']['parties'] = [party(),
        party(['ConXrefDetailID' => '602', 'ConstituentID' => '802', 'NameID' => '902', 'DisplayName' => 'Borrower', 'RoleTypeID' => '18', 'Role' => 'Bruikleennemer']),
        party(['ConXrefDetailID' => '603', 'TableID' => '108', 'ID' => '101', 'ConstituentID' => '803', 'NameID' => '903', 'DisplayName' => 'Maker Example', 'RoleTypeID' => '1', 'Role' => 'Kunstenaar']),
        party(['ConXrefDetailID' => '604', 'NameID' => '904', 'UnMasked' => '0', 'DisplayName' => 'Private collection']),
        party(['ConXrefDetailID' => '605', 'ConstituentID' => '805', 'NameID' => '905']),
        party(['ConXrefDetailID' => '606', 'Active' => '0']), party(['ConXrefDetailID' => '607', 'Displayed' => '0'])];
    $actorSync = syncer($actorEm, $actors, $directory);
    $actorResult = $actorSync->synchronize();
    check($actorResult['actors_created'] === 5 && $actorResult['actor_links'] === 10, 'Incorrect actor/assignment counts.');
    check($actorEm->getRepository(Actor::class)->count([]) === 6, 'Names/masked names must not collapse distinct identities or claim local actors.');
    $loan = $actorEm->getRepository(Project::class)->findOneBy(['tmsType' => 'loans', 'tmsId' => '10']);
    $exhibition = $actorEm->getRepository(Project::class)->findOneBy(['tmsType' => 'exhibitions', 'tmsId' => '10']);
    $actorLoanId = $loan->getId(); $actorExhId = $exhibition->getId();
    $assignment = $actorEm->getRepository(ProjectActor::class)->findOneBy(['project' => $loan, 'tmsId' => '601']);
    $borrower = $actorEm->getRepository(ProjectActor::class)->findOneBy(['project' => $loan, 'tmsId' => '602']);
    check($assignment->getRole() === ActorRole::OWNER_LENDER && $borrower->getRole() === ActorRole::BORROWER, 'Lender/borrower roles do not match TMS.');
    $actor = $assignment->getActor();
    $actorId = $actor->getId(); $assignmentId = $assignment->getId();
    check($actor->getType() === Actor::TYPE_UNKNOWN && $actor->getAddress() === 'Teststraat 1' && $actor->getPostal() === '0001' && $actor->getCountry() === 'Testland', 'Use actual source addresses and do not guess SystemFlag.');
    check($actorEm->find(Actor::class, $localActorId)->getTmsKey() === null, 'Local actor was claimed by name.');
    $object = $actorEm->getRepository(ObjectRecord::class)->findOneBy(['tmsId' => '101']);
    $actorObjectId = $object->getId();
    check($object->getCreator() === 'Maker Example', 'Incoming object maker was not mapped.');
    $links = $actorEm->getRepository(ProjectObject::class)->findBy(['project' => $exhibition]);
    foreach ($links as $link) {
        check($actorEm->getRepository(ProjectObjectActor::class)->count(['projectObject' => $link]) === ($link->getObjectRecord()->getTmsId() === '101' ? 5 : 0), 'Exhibition lender applied to an unrelated work.');
    }
    $manualAssignment = (new ProjectActor($loan, $actorEm->find(Actor::class, $localActorId)))->setRole(ActorRole::COURIER);
    $actorEm->persist($manualAssignment);
    $actor->setCity('Local city')->setType(Actor::TYPE_ORGANIZATION)->setEmail('fake@example.invalid');
    $assignment->setRole(ActorRole::OTHER)->setCustomRole('Local role');
    $actorEm->flush();
    $manualAssignmentId = $manualAssignment->getId();
    $actors['data']['parties'][0]['StreetLine1'] = 'New street 2';
    $actors['data']['parties'][0]['City'] = 'Source city';
    $again = $actorSync->synchronize();
    check($again['actors_created'] === 0 && $actorEm->getRepository(ProjectActor::class)->count([]) === 5, 'Actor import is not idempotent.');
    $actor = $actorEm->find(Actor::class, $actorId);
    check($actor->getAddress() === 'New street 2' && $actor->getCity() === 'Local city' && $actor->getType() === Actor::TYPE_ORGANIZATION && $actor->getEmail() === 'fake@example.invalid', 'Actor refresh overwrote local details or failed to update source-owned fields.');
    $assignment = $actorEm->find(ProjectActor::class, $assignmentId);
    check($assignment->getRole() === ActorRole::OTHER && $assignment->getCustomRole() === 'Local role', 'Local role override was lost.');
    $actorEm->clear();

    // Exercise the real report inheritance methods with local entities, without constructing its clients.
    $controller = (new ReflectionClass(ReportController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(ReportController::class, 'entityManager'))->setValue($controller, $actorEm);
    $series = new ReportSeries($actorEm->find(ObjectRecord::class, $actorObjectId), '', $actorEm->find(Project::class, $actorLoanId));
    $report = new Report($series);
    $actorEm->persist($series); $actorEm->persist($report);
    $keys = [];
    foreach (['copyProjectActors', 'copyProjectObjectActors'] as $method) {
        (new ReflectionMethod($controller, $method))->invokeArgs($controller, [$report, &$keys]);
    }
    $actorEm->flush();
    check($actorEm->getRepository(ReportActor::class)->count(['report' => $report]) === 6, 'New reports must inherit current project and object actors.');
    $oldReportId = $report->getId();
    $actors['disabled'] = ['parties'];
    $actorSync->synchronize();
    check($actorEm->find(ProjectActor::class, $assignmentId)->isTmsPresent() === false, 'Disabled parties left source assignments active.');
    check($actorEm->find(ProjectActor::class, $manualAssignmentId)->isTmsPresent() === null, 'Source changes modified a manual assignment.');
    $oldReport = $actorEm->find(Report::class, $oldReportId);
    check($actorEm->getRepository(ReportActor::class)->count(['report' => $oldReport]) === 6, 'Sync changed existing report assignments.');
    $newReport = new Report($oldReport->getSeries());
    $actorEm->persist($newReport);
    $keys = [];
    (new ReflectionMethod($controller, 'copyReportActors'))->invokeArgs($controller, [$oldReport, $newReport, &$keys]);
    foreach (['copyProjectActors', 'copyProjectObjectActors'] as $method) {
        (new ReflectionMethod($controller, $method))->invokeArgs($controller, [$newReport, &$keys]);
    }
    $actorEm->flush();
    check($actorEm->getRepository(ReportActor::class)->count(['report' => $newReport]) === 1, 'Stale TMS parties leaked from the previous report; local actors must remain.');
    $actors['disabled'] = [];
    $actorSync->synchronize();
    check($actorEm->find(ProjectActor::class, $assignmentId)->isTmsPresent(), 'Returning source relation must reuse its assignment.');
    // A relation-specific address change must not overwrite another relation's actor view.
    $actors['data']['parties'][0]['AddressID'] = '2001';
    $actorSync->synchronize();
    $replacement = $actorEm->find(ProjectActor::class, $assignmentId)->getActor();
    check($replacement->getId() !== $actorId && $actorEm->find(Actor::class, $actorId)->getCity() === 'Local city', 'An address variant overwrote an unrelated actor view.');
    $actorRows = $actorEm->getConnection()->fetchAllAssociative('SELECT * FROM actors');
    $assignmentRows = $actorEm->getConnection()->fetchAllAssociative('SELECT * FROM project_actors');
    $actors['data']['parties'][1]['ConstituentID'] = 'not-an-id';
    $actors['data']['parties'][0]['StreetLine1'] = 'Must roll back';
    $failed = new CommandTester(new SyncTmsProjectsCommand($actorSync));
    check($failed->execute([]) === 1, 'Invalid actor identity accepted.');
    check($actorEm->getConnection()->fetchAllAssociative('SELECT * FROM actors') === $actorRows
        && $actorEm->getConnection()->fetchAllAssociative('SELECT * FROM project_actors') === $assignmentRows, 'Failure during actor import did not roll back actors and assignment presence.');

    echo "OK: {$checks} checks; isolated SQLite and fake API only.\n";
} finally {
    // This directory was uniquely created by this test; remove only its known temporary files.
    foreach (glob($directory . '/download-*') ?: [] as $file) unlink($file);
    if (is_file($directory . '/run.lock')) unlink($directory . '/run.lock');
    rmdir($directory);
}
