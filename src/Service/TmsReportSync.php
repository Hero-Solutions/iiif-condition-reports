<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\{Actor, ProjectActor, ProjectObject, ProjectObjectActor, Report, ReportActor};
use App\Value\ActorRole;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Applies the current loan context to new reports and fills gaps in existing drafts. */
final class TmsReportSync
{
    public const ROLES = [ActorRole::OWNER_LENDER, ActorRole::BORROWER, ActorRole::COURIER, ActorRole::INSURER];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $institutionConstituentId,
        private readonly string $institutionName,
    ) {}

    /** Runs inside the caller's transaction; finalized and archived reports are never changed. */
    public function synchronizeDrafts(): int
    {
        $em = $this->entityManager;
        $lastId = $updated = 0;
        do {
            $ids = $em->createQuery('SELECT r.id FROM App\Entity\Report r JOIN r.project p WHERE r.status = :status AND p.tmsId IS NOT NULL AND p.tmsPresent = true AND r.id > :last ORDER BY r.id')
                ->setParameter('status', Report::STATUS_ACTIVE)->setParameter('last', $lastId)->setMaxResults(100)->getSingleColumnResult();
            foreach ($ids as $id) {
                $lastId = (int) $id;
                $report = $em->find(Report::class, $id);
                $em->refresh($report, LockMode::PESSIMISTIC_WRITE);
                if ($this->apply($report)) ++$updated;
                $em->flush();
                $em->clear();
            }
        } while (count($ids) === 100);
        return $updated;
    }

    /** Existing nonempty report fields are local edits. New reports use the current loan dates. */
    public function apply(Report $report, bool $new = false): bool
    {
        $project = $report->getProject();
        if (!$report->isEditable() || $project?->getTmsId() === null) return false;
        $em = $this->entityManager;
        $changed = false;
        $link = $em->getRepository(ProjectObject::class)->findOneBy(['project' => $project, 'objectRecord' => $report->getObjectRecord()]);
        if ($project->getTmsType() === 'loans') {
            foreach (['StartedAt' => $project->getStartDate(), 'EndedAt' => $project->getEndDate()] as $field => $date) {
                if ($date !== null && ($new || $report->{'get' . $field}() === null)
                    && $report->{'get' . $field}() != $date) {
                    $report->{'set' . $field}($date);
                    $changed = true;
                }
            }
        }
        if ($new || $report->getDescription() === null) {
            $parts = [$report->getDescription(), $project->getDescription(), $project->getTmsData()['record']['Remarks'] ?? null, $link?->getNotes()];
            foreach ($link?->getTmsData()['records'] ?? [] as $record) {
                $parts[] = $record['Description'] ?? null;
                $parts[] = $record['Remarks'] ?? null;
            }
            $paragraphs = [];
            foreach ($parts as $part) {
                foreach (preg_split('/\R\s*\R/u', trim((string) $part)) as $paragraph) {
                    $paragraph = trim($paragraph);
                    if ($paragraph !== '') $paragraphs[$paragraph] = $paragraph;
                }
            }
            $parts = array_values($paragraphs);
            $description = $parts === [] ? null : implode("\n\n", $parts);
            if ($description !== $report->getDescription()) {
                $report->setDescription($description);
                $changed = true;
            }
        }

        $ownRole = $project->getTmsType() === 'loans' ? match ($project->getTmsData()['record']['LoanIn'] ?? null) {
            '1' => ActorRole::BORROWER,
            '0' => ActorRole::OWNER_LENDER,
            default => null,
        } : null;
        $seen = [];
        $hasInstitution = false;
        $existing = $report->getId() === null ? [] : $em->getRepository(ReportActor::class)->findBy(['report' => $report], ['id' => 'ASC']);
        foreach ($existing as $assignment) {
            $actor = $assignment->getActor();
            $imported = $actor->getTmsKey() !== null;
            if ($imported && !in_array($assignment->getRole(), self::ROLES, true)) {
                $em->remove($assignment);
                $changed = true;
                continue;
            }
            if ($ownRole !== null && $this->isInstitution($actor)
                && in_array($assignment->getRole(), [ActorRole::OWNER_LENDER, ActorRole::BORROWER], true)
                && $assignment->getRole() !== $ownRole) {
                $assignment->setRole($ownRole);
                $changed = true;
            }
            $key = self::assignmentKey($assignment);
            if ($imported && (isset($seen[$key]) || ($hasInstitution && $this->isInstitution($actor) && $assignment->getRole() === $ownRole))) {
                $em->remove($assignment);
                $changed = true;
                continue;
            }
            $seen[$key] = true;
            if ($ownRole !== null && $this->isInstitution($actor) && $assignment->getRole() === $ownRole) $hasInstitution = true;
        }

        $sources = $em->getRepository(ProjectActor::class)->findBy(['project' => $project], ['id' => 'ASC']);
        if ($link !== null) $sources = array_merge($sources, $em->getRepository(ProjectObjectActor::class)->findBy(['projectObject' => $link], ['id' => 'ASC']));
        foreach ($sources as $source) {
            if ($source->getTmsId() === null || !$source->isTmsPresent() || !in_array($source->getRole(), self::ROLES, true)) continue;
            $role = $source->getRole();
            if ($ownRole !== null && $this->isInstitution($source->getActor())
                && in_array($role, [ActorRole::OWNER_LENDER, ActorRole::BORROWER], true)) $role = $ownRole;
            if ($hasInstitution && $this->isInstitution($source->getActor()) && $role === $ownRole) continue;
            $assignment = (new ReportActor($report, $source->getActor()))->setRole($role)->setContactPerson($source->getContactPerson());
            $key = self::assignmentKey($assignment);
            if (isset($seen[$key])) continue;
            $em->persist($assignment);
            $seen[$key] = true;
            $changed = true;
            if ($ownRole !== null && $this->isInstitution($source->getActor()) && $role === $ownRole) $hasInstitution = true;
        }
        if ($ownRole !== null && !$hasInstitution) {
            $em->persist((new ReportActor($report, $this->institution()))->setRole($ownRole));
            $changed = true;
        }
        return $changed;
    }

    /** Collapse duplicate imported assignments, preserving different names and addresses. */
    public static function assignmentKey(ReportActor|ProjectActor|ProjectObjectActor $assignment): string
    {
        $actor = $assignment->getActor();
        $identity = ['local', $actor->getId()];
        if ($actor->getTmsKey() !== null) {
            $record = $actor->getTmsData()['record'] ?? [];
            $identity = ['tms', ltrim((string) ($record['ConstituentID'] ?? ''), '0') ?: $actor->getTmsKey(), $record['UnMasked'] ?? null];
            foreach (['Name', 'Alias', 'Type', 'Address', 'Postal', 'City', 'StateProvince', 'Country', 'Email', 'Phone', 'Mobile', 'Website'] as $field) {
                $identity[] = trim((string) $actor->{'get' . $field}());
            }
        }
        return hash('sha256', json_encode([$identity, $assignment->getRole(), $assignment->getCustomRole(), $assignment->getContactPerson()?->getId()], JSON_THROW_ON_ERROR));
    }

    /** Filter imported roles and duplicate assignments for the project screen, without deleting source history. */
    public static function projectAssignments(array $assignments): array
    {
        $result = $seen = [];
        foreach ($assignments as $assignment) {
            $imported = $assignment->getTmsId() !== null;
            if ($imported && (!$assignment->isTmsPresent() || !in_array($assignment->getRole(), self::ROLES, true))) continue;
            $key = self::assignmentKey($assignment);
            if ($imported && isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $assignment;
        }
        return $result;
    }

    private function isInstitution(Actor $actor): bool
    {
        return $actor->getTmsKey() !== null
            && ltrim((string) ($actor->getTmsData()['record']['ConstituentID'] ?? ''), '0') === $this->institutionConstituentId;
    }

    private function institution(): Actor
    {
        // A configured institution default has its own key; it never impersonates a ConXrefDetail row.
        $key = hash('sha256', 'tms-institution:' . $this->institutionConstituentId);
        $actor = $this->entityManager->getRepository(Actor::class)->findOneBy(['tmsKey' => $key]);
        if ($actor === null) {
            $actor = new Actor($this->institutionName, Actor::TYPE_ORGANIZATION);
            $actor->applyTmsSource($key, ['institution_default' => true, 'record' => [
                'ConstituentID' => $this->institutionConstituentId, 'DisplayName' => $this->institutionName, 'UnMasked' => '1',
            ]]);
            $this->entityManager->persist($actor);
        }
        return $actor;
    }
}
