<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\{ObjectLogEntry, ObjectRecord, Project, ProjectObject};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

/** Downloads all datasets before applying it in a single database transaction. */
final class TmsProjectSync
{
    private const REQUIRED = ['exhibitions', 'loans', 'objects', 'object_titles',
        'exhibition_objects', 'loan_objects', 'exhibition_loans', 'shipments'];
    private const OPTIONAL = ['parties', 'documents'];
    private const SOURCE_IDS = [
        'exhibitions' => ['ExhibitionID'],
        'loans' => ['LoanID'],
        'objects' => ['ObjectID'],
        'object_titles' => ['TitleID', 'ObjectID'],
        'exhibition_objects' => ['ExhObjXrefID', 'ExhibitionID', 'ObjectID'],
        'loan_objects' => ['LoanObjXrefID', 'LoanID', 'ObjectID'],
        'exhibition_loans' => ['ExhibitionID', 'LoanID'],
        'shipments' => ['LoanID'],
        'parties' => ['ConXrefDetailID', 'TableID', 'ID'],
        'documents' => ['MediaXrefID', 'TableID', 'ID'],
    ];

    public function __construct(
        private readonly TmsApiClient $client,
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectChangeLogger $objectLogger,
        private readonly string $workDirectory,
        private readonly TmsActorSync $actorSync,
        private readonly TmsReportSync $reportSync,
    ) {
    }

    /** @param null|callable(string): void $trace */
    public function synchronize(?callable $trace = null): array
    {
        if (!$this->client->isEnabled()) {
            return ['status' => 'disabled'];
        }
        if (!is_dir($this->workDirectory) && !@mkdir($this->workDirectory, 0700, true) && !is_dir($this->workDirectory)) {
            throw new UnexpectedValueException('De tijdelijke importmap kan niet worden aangemaakt.');
        }
        $lock = @fopen($this->workDirectory . '/run.lock', 'c');
        if ($lock === false) {
            throw new UnexpectedValueException('Het importslot kan niet worden geopend.');
        }
        $files = [];
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return ['status' => 'locked'];
            }
            if ($trace !== null) $trace('Importslot verkregen; API-download starten.');
            $manifest = $this->download($files, $trace);
            if ($trace !== null) $trace('Alle API-pagina\'s gecontroleerd; databasetransactie starten.');
            // No project/object mutations (or database connection) before download completion.
            return $this->entityManager->wrapInTransaction(function () use ($files, $manifest): array {
                return $this->apply($files, $manifest);
            });
        } finally {
            foreach ($files as $file) {
                $path = stream_get_meta_data($file)['uri'];
                fclose($file);
                @unlink($path);
            }
            fclose($lock);
        }
    }

    /** @param array<string, resource> $files */
    private function download(array &$files, ?callable $trace): array
    {
        $manifest = $this->client->getManifest($trace);
        if (($manifest['scope'] ?? null) !== ['exhibition_ids' => null]) {
            throw new UnexpectedValueException('Deze import verwacht alle bruiklenen en tentoonstellingen; de API-scope is beperkt of ontbreekt.');
        }
        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $dataset) {
            $enabled = $manifest['datasets'][$dataset]['enabled'] ?? null;
            if (!is_bool($enabled) || (!$enabled && in_array($dataset, self::REQUIRED, true))) {
                throw new UnexpectedValueException('Een vereiste API-dataset ontbreekt of is uitgeschakeld: ' . $dataset);
            }
            if (!$enabled) {
                if ($trace !== null) $trace('Dataset uitgeschakeld: ' . $dataset);
                continue;
            }
            $path = $this->workDirectory . '/download-' . bin2hex(random_bytes(12));
            $file = @fopen($path, 'x+b');
            if ($file === false) {
                throw new UnexpectedValueException('De tijdelijke API-download kan niet worden geopend.');
            }
            $files[$dataset] = $file;
            $offset = 0;
            do {
                $page = $this->client->getPage($dataset, $offset, 500, $trace);
                if (($page['scope'] ?? null) !== $manifest['scope']) {
                    throw new UnexpectedValueException('De API-scope is tijdens het ophalen gewijzigd.');
                }
                foreach ($page['data'] as $rowIndex => $row) {
                    if (!is_array($row) || $row === [] || array_is_list($row)) {
                        throw new UnexpectedValueException('Ongeldige rij in API-dataset: ' . $dataset);
                    }
                    foreach ($row as $value) {
                        if ($value !== null && !is_string($value)) {
                            throw new UnexpectedValueException('De API moet bronwaarden als tekst of null leveren: ' . $dataset);
                        }
                    }
                    $columns = match ($dataset) {
                        'exhibitions' => ['ExhibitionID', 'ProjectNumber', 'ExhTitle', 'DisplayDate'],
                        'loans' => ['LoanID', 'LoanNumber', 'LoanIn', 'Description', 'BeginISODate', 'EndISODate', 'LoanStatus'],
                        'objects' => ['ObjectID', 'ObjectNumber', 'ObjectName', 'Dated'],
                        'parties' => ['ConXrefDetailID', 'ConstituentID', 'NameID', 'AddressID', 'UnMasked',
                            'DisplayName', 'Role', 'RoleTypeID', 'Active', 'Displayed', 'AddressActive',
                            'StreetLine1', 'StreetLine2', 'StreetLine3', 'ZipCode', 'City', 'State', 'Country'],
                        default => [],
                    };
                    foreach ($columns as $column) {
                        if (!array_key_exists($column, $row)) {
                            throw new UnexpectedValueException('Een verwachte bronkolom ontbreekt: ' . $dataset . '.' . $column);
                        }
                    }
                    // Check identities on arrival, before requesting further pages or opening a transaction.
                    try {
                        foreach (self::SOURCE_IDS[$dataset] as $key) $this->id($row, $key);
                        if ($dataset === 'parties') TmsActorSync::sourceIdentity($row);
                    } catch (UnexpectedValueException $error) {
                        throw new UnexpectedValueException($error->getMessage() . sprintf(
                            ' (dataset %s, pagina-offset %d, rij %d, rij-offset %d).',
                            $dataset, $offset, $rowIndex + 1, $offset + $rowIndex), 0, $error);
                    }
                    $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                    if (fwrite($file, $line) !== strlen($line)) {
                        throw new UnexpectedValueException('De tijdelijke API-download is onvolledig geschreven.');
                    }
                }
                $offset = $page['next_offset'];
            } while ($offset !== null);
        }
        if ($trace !== null) $trace('Download voltooid; API-manifest opnieuw controleren.');
        $end = $this->client->getManifest($trace);
        if ($end['datasets'] !== $manifest['datasets']
            || ($end['scope'] ?? null) !== $manifest['scope']) {
            throw new UnexpectedValueException('De API-configuratie is tijdens het ophalen gewijzigd. Probeer opnieuw.');
        }
        return $manifest;
    }

    private function rows(array $files, string $dataset): \Generator
    {
        if (!isset($files[$dataset])) {
            return;
        }
        $file = $files[$dataset];
        rewind($file);
        while (!feof($file)) {
            $offset = ftell($file);
            $line = fgets($file);
            if ($line === false) break;
            if ($offset === false) {
                throw new UnexpectedValueException('De tijdelijke API-download kan niet worden geïndexeerd.');
            }
            yield $offset => json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!feof($file)) {
            throw new UnexpectedValueException('De tijdelijke API-download kon niet volledig worden gelezen.');
        }
    }

    private function rowAt(array $files, string $dataset, int $offset): array
    {
        $file = $files[$dataset];
        if (fseek($file, $offset) !== 0 || ($line = fgets($file)) === false) {
            throw new UnexpectedValueException('De tijdelijke API-download kon niet volledig worden gelezen: ' . $dataset);
        }
        return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    }

    /** Load only the metadata belonging to the current object or project. */
    private function readRows(array $files, string $dataset, array $offsets): array
    {
        $rows = [];
        foreach ($offsets as $offset) $rows[] = $this->rowAt($files, $dataset, $offset);
        return $rows;
    }

    private function apply(array $files, array $manifest): array
    {
        $at = new \DateTimeImmutable();
        $importId = bin2hex(random_bytes(16)); // Local trace ID, not a source snapshot guarantee.
        $result = ['status' => 'completed', 'import_id' => $importId,
            'objects' => 0, 'objects_created' => 0, 'projects' => 0, 'projects_created' => 0, 'links' => 0,
            'optional_disabled' => array_values(array_filter(self::OPTIONAL,
                static fn(string $dataset): bool => !$manifest['datasets'][$dataset]['enabled']))];

        $titles = $this->group($files, 'object_titles', 'ObjectID', 'TitleID');
        $shipments = $this->group($files, 'shipments', 'LoanID');
        $parties = $documents = [];
        foreach (['parties' => 'ConXrefDetailID', 'documents' => 'MediaXrefID'] as $dataset => $pk) {
            $seen = [];
            foreach ($this->rows($files, $dataset) as $offset => $row) {
                $this->unique($seen, $this->id($row, $pk), $dataset);
                $table = $this->id($row, 'TableID');
                if (!in_array($table, ['81', '108'], true)) {
                    throw new UnexpectedValueException('Onbekende relatie in API-dataset: ' . $dataset);
                }
                $parentId = $this->id($row, 'ID');
                if ($dataset === 'parties') {
                    $parties[$table][$parentId][] = $offset;
                } else {
                    $documents[$table][$parentId][] = $offset;
                }
            }
        }
        unset($seen);

        $objects = [];
        foreach ($this->rows($files, 'objects') as $row) {
            $id = $this->id($row, 'ObjectID');
            $this->unique($objects, $id, 'objects');
            $number = $this->text($row, 'ObjectNumber');
            if ($number === null || mb_strlen($number) > 100) {
                throw new UnexpectedValueException('TMS-object ' . $id . ' heeft geen bruikbaar inventarisnummer.');
            }
            $repository = $this->entityManager->getRepository(ObjectRecord::class);
            $object = $repository->findOneBy(['tmsId' => $id]);
            $byNumber = $repository->findOneBy(['inventoryNumber' => $number]);
            if ($byNumber !== null && ($byNumber->getInventoryNumber() !== $number
                || ($object !== null && $byNumber->getId() !== $object->getId())
                || ($byNumber->getTmsId() !== null && $byNumber->getTmsId() !== $id))) {
                throw new UnexpectedValueException('Dubbelzinnige objectkoppeling voor TMS-object ' . $id . '; los dit eerst handmatig op.');
            }
            $object ??= $byNumber;
            $new = $object === null;
            if (!$new) {
                $this->entityManager->refresh($object, LockMode::PESSIMISTIC_WRITE);
            }
            $objectTitles = $this->readRows($files, 'object_titles', $titles[$id] ?? []);
            $objectParties = $this->readRows($files, 'parties', $parties['108'][$id] ?? []);
            $title = $this->objectTitle($objectTitles) ?? $this->text($row, 'ObjectName') ?? $number;
            if ($new) {
                $object = (new ObjectRecord($number, mb_substr($title, 0, 255)))->setSource(ObjectRecord::SOURCE_TMS, $id);
                $this->entityManager->persist($object);
                ++$result['objects_created'];
            }
            $before = $new ? [] : $this->objectLogger->snapshot($object);
            $values = ['inventoryNumber' => $number, 'title' => mb_substr($title, 0, 255)];
            if (isset($files['parties'])) {
                $values['creator'] = TmsActorSync::creators($objectParties);
            } elseif (array_key_exists('creator', $object->getTmsData()['mapped'] ?? [])) {
                $values['creator'] = $object->getTmsData()['mapped']['creator'];
            }
            // DataHub/manual fields and images remain authoritative on already existing objects.
            if ($object->getSource() === ObjectRecord::SOURCE_TMS) {
                $hadLocalChanges = $object->getSyncStatus() === ObjectRecord::SYNC_LOCAL_CHANGES;
                $this->mergeFields($object, $values, $object->getTmsData()['mapped'] ?? null);
                if (!$hadLocalChanges) {
                    $object->markSynced($at);
                }
            }
            $object->applyTmsSource($id, ['record' => $row, 'titles' => $objectTitles,
                'parties' => $objectParties, 'documents' => $this->readRows($files, 'documents', $documents['108'][$id] ?? []),
                'mapped' => $values, 'import_id' => $importId], $at);
            $this->objectLogger->record($object, $before, ObjectLogEntry::TMS_SYNC, createdAt: $at);
            $this->entityManager->flush();
            $objects[$id] = $object->getId();
            ++$result['objects'];
            $this->entityManager->clear();
        }
        foreach ([$titles, $parties['108'] ?? [], $documents['108'] ?? []] as $related) {
            $this->requireParents($related, $objects, 'objects');
        }
        unset($titles, $documents['108']);

        // Only TMS presence flags change for missing records; local workflow and history survive.
        $this->entityManager->clear();
        $this->entityManager->createQuery('UPDATE App\\Entity\\Project p SET p.tmsPresent = false WHERE p.tmsId IS NOT NULL')->execute();
        $this->entityManager->createQuery('UPDATE App\\Entity\\ProjectObject po SET po.tmsPresent = false WHERE po.tmsPresent IS NOT NULL')->execute();
        $projects = ['loans' => [], 'exhibitions' => []];
        $exhibitionLoans = $this->group($files, 'exhibition_loans', 'ExhibitionID');
        $loanExhibitions = [];
        foreach ($exhibitionLoans as $exhibitionId => $links) {
            foreach ($links as $offset) {
                $link = $this->rowAt($files, 'exhibition_loans', $offset);
                $loanExhibitions[$this->id($link, 'LoanID')][] = (string) $exhibitionId;
            }
        }
        foreach (['loans' => 'LoanID', 'exhibitions' => 'ExhibitionID'] as $dataset => $pk) {
            $isLoan = $dataset === 'loans';
            foreach ($this->rows($files, $dataset) as $row) {
                $id = $this->id($row, $pk);
                $this->unique($projects[$dataset], $id, $dataset);
                $project = $this->entityManager->getRepository(Project::class)->findOneBy(['tmsType' => $dataset, 'tmsId' => $id]);
                if ($project === null) {
                    $project = new Project();
                    $this->entityManager->persist($project);
                    ++$result['projects_created'];
                } else {
                    $this->entityManager->refresh($project, LockMode::PESSIMISTIC_WRITE);
                }
                $reference = $this->text($row, $isLoan ? 'LoanNumber' : 'ProjectNumber');
                if ($reference !== null && mb_strlen($reference) > 100) {
                    throw new UnexpectedValueException('Te lang referentienummer voor TMS-project ' . $id);
                }
                $title = $this->text($row, $isLoan ? 'Description' : 'ExhTitle')
                    ?? $reference ?? ($isLoan ? 'Bruikleen ' : 'Tentoonstelling ') . $id;
                $values = ['title' => mb_substr($title, 0, 255),
                    'type' => $isLoan ? Project::TYPE_LOAN : Project::TYPE_EXHIBITION,
                    'referenceCode' => $reference, 'description' => $isLoan ? $this->text($row, 'Description') : null,
                    'startDate' => $isLoan ? $this->date($row, 'BeginISODate') : null,
                    'endDate' => $isLoan ? $this->date($row, 'EndISODate') : null];
                $this->mergeFields($project, $values, $project->getTmsData()['mapped'] ?? null);
                $project->applyTmsSource($dataset, $id, ['record' => $row, 'mapped' => $values,
                    'loan_ids' => $isLoan ? [] : array_values(array_unique(array_map(fn(array $link): string => $this->id($link, 'LoanID'), $this->readRows($files, 'exhibition_loans', $exhibitionLoans[$id] ?? [])))),
                    'exhibition_ids' => $isLoan ? array_values(array_unique($loanExhibitions[$id] ?? [])) : [],
                    'parties' => $isLoan ? $this->readRows($files, 'parties', $parties['81'][$id] ?? []) : [],
                    'documents' => $isLoan ? $this->readRows($files, 'documents', $documents['81'][$id] ?? []) : [],
                    'shipments' => $isLoan ? $this->readRows($files, 'shipments', $shipments[$id] ?? []) : [],
                    'import_id' => $importId], $at);
                $this->entityManager->flush();
                $projects[$dataset][$id] = $project->getId();
                ++$result['projects'];
                $this->entityManager->clear();
            }
        }
        $this->requireParents($exhibitionLoans, $projects['exhibitions'], 'exhibitions');
        foreach ([$loanExhibitions, $shipments, $parties['81'] ?? [], $documents['81'] ?? []] as $related) {
            $this->requireParents($related, $projects['loans'], 'loans');
        }
        unset($documents, $shipments, $exhibitionLoans, $loanExhibitions);

        foreach (['loan_objects' => ['loans', 'LoanID', 'LoanObjXrefID'],
            'exhibition_objects' => ['exhibitions', 'ExhibitionID', 'ExhObjXrefID']] as $dataset => [$kind, $parentKey, $pk]) {
            $groups = $this->group($files, $dataset, $parentKey, $pk);
            $this->requireParents($groups, $projects[$kind], $kind);
            foreach ($groups as $parent => $offsets) {
                $byObject = [];
                foreach ($offsets as $offset) {
                    $row = $this->rowAt($files, $dataset, $offset);
                    $byObject[$this->id($row, 'ObjectID')][] = $offset;
                }
                $this->requireParents($byObject, $objects, 'objects');
                foreach ($byObject as $id => $sourceOffsets) {
                    $project = $this->entityManager->getReference(Project::class, $projects[$kind][$parent]);
                    $object = $this->entityManager->getReference(ObjectRecord::class, $objects[$id]);
                    $link = $this->entityManager->getRepository(ProjectObject::class)->findOneBy(['project' => $project, 'objectRecord' => $object]);
                    if ($link === null) {
                        $link = new ProjectObject($project, $object);
                        $this->entityManager->persist($link);
                    } else {
                        $this->entityManager->refresh($link, LockMode::PESSIMISTIC_WRITE);
                    }
                    $link->applyTmsSource(['records' => $this->readRows($files, $dataset, $sourceOffsets), 'import_id' => $importId]);
                    ++$result['links'];
                    if ($result['links'] % 100 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }
                }
            }
        }
        $this->entityManager->flush();
        unset($groups, $byObject, $objects);
        $result += $this->actorSync->synchronize($parties, $projects, $importId,
            fn(int $offset): array => $this->rowAt($files, 'parties', $offset));
        $result['drafts_updated'] = $this->reportSync->synchronizeDrafts();
        $result['projects_missing'] = (int) $this->entityManager->createQuery('SELECT COUNT(p.id) FROM App\\Entity\\Project p WHERE p.tmsId IS NOT NULL AND p.tmsPresent = false')->getSingleScalarResult();
        $result['links_missing'] = (int) $this->entityManager->createQuery('SELECT COUNT(po.id) FROM App\\Entity\\ProjectObject po WHERE po.tmsPresent = false')->getSingleScalarResult();
        return $result;
    }

    /** Index file positions, not decoded rows: source payloads stay on disk. */
    private function group(array $files, string $dataset, string $parentKey, ?string $pk = null): array
    {
        $groups = $seen = [];
        foreach ($this->rows($files, $dataset) as $offset => $row) {
            if ($pk !== null) {
                $this->unique($seen, $this->id($row, $pk), $dataset);
            }
            $groups[$this->id($row, $parentKey)][] = $offset;
        }
        return $groups;
    }

    private function unique(array &$seen, string $id, string $dataset): void
    {
        if (array_key_exists($id, $seen)) {
            throw new UnexpectedValueException('Dubbele bron-ID ' . $id . ' in dataset ' . $dataset);
        }
        $seen[$id] = true;
    }

    private function requireParents(array $groups, array $parents, string $dataset): void
    {
        foreach ($groups as $id => $_) {
            if (!isset($parents[$id])) {
                throw new UnexpectedValueException('Een relatie verwijst naar ontbrekende bron-ID ' . $id . ' in ' . $dataset);
            }
        }
    }

    private function id(array $row, string $key): string
    {
        $id = $row[$key] ?? null;
        // The supplied legacy mirror contains TitleID 0 and MediaXrefID 0.
        if (!is_string($id) || !preg_match('/^[0-9]{1,18}$/D', $id)
            || (!in_array($key, ['TitleID', 'MediaXrefID'], true) && trim($id, '0') === '')) {
            // Describe malformed values without logging arbitrary source text.
            $reason = match (true) {
                !array_key_exists($key, $row) => 'kolom ontbreekt',
                $id === null => 'waarde is null',
                !is_string($id) => 'waarde is geen tekst',
                $id === '' => 'waarde is leeg',
                strlen($id) <= 18 && trim($id, '0') === '' => 'waarde is 0; een positief ID is vereist',
                default => 'verwacht 1 tot 18 cijfers zonder spaties of andere tekens',
            };
            throw new UnexpectedValueException('Ontbrekende of ongeldige TMS-ID: ' . $key . '; ' . $reason);
        }
        return ltrim($id, '0') ?: '0';
    }

    private function text(array $row, string $key): ?string
    {
        $text = trim($row[$key] ?? '');
        return $text === '' ? null : $text;
    }

    private function date(array $row, string $key): ?string
    {
        $value = $this->text($row, $key);
        // TMS permits partial/qualified dates. Preserve those in source metadata, never guess a day.
        if ($value === null || !preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }
        return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
    }

    /** Update fields only while they still equal the previous imported value. */
    private function mergeFields(Project|ObjectRecord $entity, array $values, ?array $previous): void
    {
        foreach ($values as $field => $value) {
            $current = $entity->{'get' . ucfirst($field)}();
            $current = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : $current;
            if (($previous === null || (array_key_exists($field, $previous) ? $current === $previous[$field] : $current === null)) && $current !== $value) {
                $entity->{'set' . ucfirst($field)}(in_array($field, ['startDate', 'endDate'], true) && $value !== null
                    ? new \DateTimeImmutable($value) : $value);
            }
        }
    }

    private function objectTitle(array $titles): ?string
    {
        $titles = array_filter($titles, fn(array $row): bool => ($row['Active'] ?? null) !== '0' && $this->text($row, 'Title') !== null);
        usort($titles, static fn(array $a, array $b): int =>
            [($a['Displayed'] ?? null) === '1' ? 0 : 1, ($a['LanguageID'] ?? null) === '2' ? 0 : 1, (int) ($a['DisplayOrder'] ?? PHP_INT_MAX), (int) $a['TitleID']]
            <=> [($b['Displayed'] ?? null) === '1' ? 0 : 1, ($b['LanguageID'] ?? null) === '2' ? 0 : 1, (int) ($b['DisplayOrder'] ?? PHP_INT_MAX), (int) $b['TitleID']]);
        return $titles === [] ? null : $this->text($titles[0], 'Title');
    }
}
