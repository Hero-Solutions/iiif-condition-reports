<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\{Actor, Project, ProjectActor, ProjectObject, ProjectObjectActor};
use App\Value\ActorRole;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

/** Runs inside the project import transaction. */
final class TmsActorSync
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @param callable(int): array $readParty Reads one row from the private download file. */
    public function synchronize(array $parties, array $projects, string $importId, callable $readParty): array
    {
        $em = $this->entityManager;
        $em->flush();
        $em->clear();
        foreach ([ProjectActor::class, ProjectObjectActor::class] as $class) {
            $em->createQuery('UPDATE ' . $class . ' a SET a.tmsPresent = false WHERE a.tmsId IS NOT NULL')->execute();
        }
        $result = ['actors' => 0, 'actors_created' => 0, 'actor_links' => 0];
        $actors = $valueHashes = $available = [];
        foreach ($parties as $table => $parents) {
            foreach ($parents as $parent => $offsets) {
                foreach ($offsets as $offset) {
                    $row = $readParty($offset);
                    $identity = self::sourceIdentity($row);
                    if ($identity === null) continue;
                    $detail = self::sourceId($row, 'ConXrefDetailID');
                    $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
                    $values = self::actorValues($row);
                    $valueHash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR), true);
                    if (isset($valueHashes[$key]) && $valueHashes[$key] !== $valueHash) {
                        throw new UnexpectedValueException('Tegenstrijdige naam/adresgegevens voor TMS-actorrelatie ' . $detail);
                    }
                    $valueHashes[$key] = $valueHash;
                    if (!isset($actors[$key])) {
                        $actor = $em->getRepository(Actor::class)->findOneBy(['tmsKey' => $key]);
                        if ($actor === null) {
                            // SystemFlag has no proven person/organization mapping in this contract.
                            $actor = new Actor('', Actor::TYPE_UNKNOWN);
                            $em->persist($actor);
                            ++$result['actors_created'];
                        } else {
                            $em->refresh($actor, LockMode::PESSIMISTIC_WRITE);
                        }
                        $previous = $actor->getTmsData()['mapped'] ?? null;
                        foreach ($values as $field => $value) {
                            if ($previous === null || $actor->{'get' . ucfirst($field)}() === ($previous[$field] ?? null)) {
                                $actor->{'set' . ucfirst($field)}($value);
                            }
                        }
                        $actor->applyTmsSource($key, ['record' => $row, 'mapped' => $values, 'import_id' => $importId]);
                        $em->flush();
                        $actors[$key] = $actor->getId();
                        ++$result['actors'];
                        $em->clear();
                    }
                    // Keep only the actor ID and file position, not every decoded source row.
                    $available[$table][$parent][$detail] = [$actors[$key], $offset];
                }
            }
        }
        unset($actors, $valueHashes);
        foreach ($projects['loans'] as $id => $projectId) {
            foreach ($available['81'][$id] ?? [] as $detail => [$actorId, $offset]) {
                $this->assign($em->getReference(Project::class, $projectId), (string) $detail, $actorId, $readParty($offset), $importId);
                ++$result['actor_links'];
            }
        }
        // A lender applies to an exhibition work only when both TMS links exist.
        $loansByObject = [];
        foreach ($projects['loans'] as $loanId => $projectId) {
            $rows = $em->createQuery('SELECT o.tmsId FROM App\Entity\ProjectObject po JOIN po.objectRecord o WHERE IDENTITY(po.project) = :project AND po.tmsPresent = true')
                ->setParameter('project', $projectId)->getScalarResult();
            foreach ($rows as $row) $loansByObject[$row['tmsId']][] = $loanId;
        }
        foreach ($projects as $kind => $ids) {
            foreach ($ids as $projectId) {
                $links = $em->createQuery('SELECT po.id FROM App\Entity\ProjectObject po WHERE IDENTITY(po.project) = :project AND po.tmsPresent = true')
                    ->setParameter('project', $projectId)->getScalarResult();
                foreach ($links as $linkRow) {
                    $link = $em->find(ProjectObject::class, $linkRow['id']);
                    $objectId = $link->getObjectRecord()->getTmsId();
                    $sources = $available['108'][$objectId] ?? [];
                    if ($kind === 'exhibitions') {
                        $loanIds = $link->getProject()->getTmsData()['loan_ids'] ?? [];
                        foreach ($loansByObject[$objectId] ?? [] as $loanId) {
                            if (in_array((string) $loanId, $loanIds, true)) $sources += $available['81'][$loanId] ?? [];
                        }
                    }
                    foreach ($sources as $detail => [$actorId, $offset]) {
                        $this->assign($em->find(ProjectObject::class, $linkRow['id']), (string) $detail, $actorId, $readParty($offset), $importId);
                        ++$result['actor_links'];
                    }
                    $em->clear();
                }
            }
        }
        return $result;
    }

    private function assign(Project|ProjectObject $parent, string $id, int $actorId, array $row, string $importId): void
    {
        $em = $this->entityManager;
        $class = $parent instanceof Project ? ProjectActor::class : ProjectObjectActor::class;
        $parentKey = $parent instanceof Project ? 'project' : 'projectObject';
        $actor = $em->getReference(Actor::class, $actorId);
        $assignment = $em->getRepository($class)->findOneBy([$parentKey => $parent, 'tmsId' => $id]);
        if ($assignment === null) {
            $assignment = new $class($parent, $actor);
            $em->persist($assignment);
        } else {
            $em->refresh($assignment, LockMode::PESSIMISTIC_WRITE);
        }
        $mapped = self::role($row);
        $previous = $assignment->getTmsData()['mapped'] ?? null;
        if ($previous === null || [$assignment->getRole(), $assignment->getCustomRole()] === $previous) {
            $assignment->setRole($mapped[0])->setCustomRole($mapped[1]);
        }
        $assignment->applyTmsSource($id, $actor, ['record' => $row, 'mapped' => $mapped, 'import_id' => $importId]);
        $em->flush();
        $em->clear();
    }

    public static function visible(array $row): bool
    {
        return ($row['Active'] ?? null) !== '0' && ($row['Displayed'] ?? null) !== '0';
    }

    /** Shared by page validation and import. Invisible or unnamed relations do not create actors. */
    public static function sourceIdentity(array $row): ?array
    {
        if (!self::visible($row) || trim($row['DisplayName'] ?? '') === '') return null;
        $identity = [self::sourceId($row, 'ConstituentID'), self::sourceId($row, 'NameID'),
            empty($row['AddressID']) ? '0' : self::sourceId($row, 'AddressID')];
        if (!in_array($row['UnMasked'] ?? null, ['0', '1'], true)) {
            throw new UnexpectedValueException('Ongeldige zichtbaarheid voor TMS-actorrelatie ' . self::sourceId($row, 'ConXrefDetailID'));
        }
        // UnMasked belongs to the relation; the exact supplied name/address IDs identify the actor.
        return $identity;
    }

    public static function creators(array $rows): ?string
    {
        $names = [];
        foreach ($rows as $row) {
            if (self::visible($row) && in_array(mb_strtolower(trim($row['Role'] ?? '')),
                ['artist', 'maker', 'creator', 'vervaardiger', 'kunstenaar', 'schilder'], true)) {
                $name = trim($row['DisplayName'] ?? '');
                if ($name !== '') $names[$name] = $name;
            }
        }
        return $names === [] ? null : implode('; ', $names);
    }

    private static function sourceId(array $row, string $field): string
    {
        $id = $row[$field] ?? null;
        // The supplied legacy mirror uses AddressID -1. Keep this variant distinct from 0 and actual address IDs.
        if ($field === 'AddressID' && $id === '-1') return '-1';
        if (!is_string($id) || !preg_match('/^[0-9]{1,18}$/D', $id) || trim($id, '0') === '') {
            $reason = match (true) {
                !array_key_exists($field, $row) => 'kolom ontbreekt',
                $id === null => 'waarde is null',
                !is_string($id) => 'waarde is geen tekst',
                $id === '' => 'waarde is leeg',
                (bool) preg_match('/^-?[0-9]{1,18}$/D', $id) => 'waarde ' . $id . '; een positief ID is vereist',
                default => 'verwacht 1 tot 18 cijfers zonder spaties of andere tekens',
            };
            // Only print a bounded numeric relation ID, never arbitrary source text or actor/address fields.
            $detail = $row['ConXrefDetailID'] ?? null;
            $context = is_string($detail) && preg_match('/^[0-9]{1,18}$/D', $detail) ? '; actorrelatie ' . $detail : '';
            throw new UnexpectedValueException('Ontbrekende of ongeldige TMS-actor-ID: ' . $field . '; ' . $reason . $context);
        }
        return ltrim($id, '0');
    }

    private static function actorValues(array $row): array
    {
        $text = static function (string $field) use ($row): ?string {
            $value = trim($row[$field] ?? '');
            return $value === '' ? null : mb_substr($value, 0, 255);
        };
        $active = ($row['AddressActive'] ?? null) !== '0';
        $street = implode("\n", array_filter([$text('StreetLine1'), $text('StreetLine2'), $text('StreetLine3')], static fn($v) => $v !== null));
        return ['name' => $text('DisplayName'), 'address' => $active && $street !== '' ? mb_substr($street, 0, 255) : null,
            'postal' => $active ? $text('ZipCode') : null, 'city' => $active ? $text('City') : null,
            'stateProvince' => $active ? $text('State') : null, 'country' => $active ? $text('Country') : null];
    }

    private static function role(array $row): array
    {
        // RoleType 17/18 are evidenced by the supplied KMSKA loan exports.
        $role = match ($row['RoleTypeID'] ?? null) {
            '17' => ActorRole::OWNER_LENDER,
            '18' => ActorRole::BORROWER,
            default => match (mb_strtolower(trim($row['Role'] ?? ''))) {
                'bruikleengever', 'eigenaar', 'lender', 'owner' => ActorRole::OWNER_LENDER,
                'bruikleennemer', 'borrower' => ActorRole::BORROWER,
                'restaurator', 'conservator' => ActorRole::CONSERVATOR,
                'koerier', 'courier' => ActorRole::COURIER,
                'art handler' => ActorRole::ART_HANDLER,
                'verzekeraar', 'insurer' => ActorRole::INSURER,
                default => ActorRole::OTHER,
            },
        };
        return [$role, $role === ActorRole::OTHER ? mb_substr(trim($row['Role'] ?? '') ?: 'TMS', 0, 100) : null];
    }
}
