<?php

declare(strict_types=1);

namespace App\Entity;

use App\Value\ActorRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_actors')]
#[ORM\Index(name: 'idx_report_actor_report', columns: ['report_id'])]
#[ORM\Index(name: 'idx_report_actor_actor', columns: ['actor_id'])]
class ReportActor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne(targetEntity: Actor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Actor $actor;

    #[ORM\ManyToOne(targetEntity: OrganizationContact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OrganizationContact $contactPerson = null;

    #[ORM\Column(length: 50)]
    private string $role = ActorRole::OTHER;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customRole = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Report $report, Actor $actor)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->report = $report;
        $this->actor = $actor;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getActor(): Actor
    {
        return $this->actor;
    }

    public function getContactPerson(): ?OrganizationContact
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?OrganizationContact $contactPerson): self
    {
        if ($contactPerson !== null && (
            $this->actor->getType() !== Actor::TYPE_ORGANIZATION
            || $contactPerson->getOrganization() !== $this->actor
        )) {
            throw new \InvalidArgumentException('The contact person must belong to the selected organization.');
        }

        $this->contactPerson = $contactPerson;
        $this->touch();

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = ActorRole::normalize($role) ?? ActorRole::OTHER;

        if ($this->role !== ActorRole::OTHER) {
            $this->customRole = null;
        }

        $this->touch();

        return $this;
    }

    public function getCustomRole(): ?string
    {
        return $this->customRole;
    }

    public function setCustomRole(?string $customRole): self
    {
        $this->customRole = $this->nullableText($customRole);
        $this->touch();

        return $this;
    }

    public function normalizeCustomRole(): self
    {
        if ($this->role !== ActorRole::OTHER) {
            $this->customRole = null;
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
