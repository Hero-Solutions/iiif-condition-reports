<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'annotations')]
#[ORM\UniqueConstraint(name: 'uniq_annotation_client_id', columns: ['report_id', 'client_id'])]
class Annotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne(targetEntity: DamageCase::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DamageCase $damageCase;

    #[ORM\ManyToOne(targetEntity: ReportImage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReportImage $reportImage = null;

    #[ORM\Column(length: 255)]
    private string $sourceKey;

    #[ORM\Column(length: 255)]
    private string $clientId;

    #[ORM\Column(type: Types::JSON)]
    private array $target = [];

    #[ORM\Column(type: Types::JSON)]
    private array $bodies = [];

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $deleted = false;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $createdById = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Report $report, DamageCase $damageCase, string $sourceKey, string $clientId)
    {
        if ($damageCase->getReport() !== $report) {
            throw new \InvalidArgumentException('Damage case and annotation must belong to the same report.');
        }

        $now = new \DateTimeImmutable();
        $this->report = $report;
        $this->damageCase = $damageCase;
        $this->sourceKey = trim($sourceKey);
        $this->clientId = trim($clientId);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getDamageCase(): DamageCase
    {
        return $this->damageCase;
    }

    public function setDamageCase(DamageCase $damageCase): self
    {
        if ($damageCase->getReport() !== $this->report) {
            throw new \InvalidArgumentException('Damage case and annotation must belong to the same report.');
        }

        $this->damageCase = $damageCase;
        $this->touch();

        return $this;
    }

    public function getReportImage(): ?ReportImage
    {
        return $this->reportImage;
    }

    public function setReportImage(?ReportImage $reportImage): self
    {
        if ($reportImage instanceof ReportImage && $reportImage->getReport() !== $this->report) {
            throw new \InvalidArgumentException('Report image and annotation must belong to the same report.');
        }

        $this->reportImage = $reportImage;
        $this->touch();

        return $this;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function setSourceKey(string $sourceKey): self
    {
        $this->sourceKey = trim($sourceKey);
        $this->touch();

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getTarget(): array
    {
        return $this->target;
    }

    public function setTarget(array $target): self
    {
        $this->target = $target;
        $this->touch();

        return $this;
    }

    public function getBodies(): array
    {
        return $this->bodies;
    }

    public function setBodies(array $bodies): self
    {
        $this->bodies = $bodies;
        $this->touch();

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = max(0, $sortOrder);
        $this->touch();

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function delete(): self
    {
        $this->deleted = true;
        $this->touch();

        return $this;
    }

    public function restore(): self
    {
        $this->deleted = false;
        $this->touch();

        return $this;
    }

    public function getCreatedById(): ?int
    {
        return $this->createdById;
    }

    public function setCreatedById(?int $createdById): self
    {
        $this->createdById = $createdById;

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
