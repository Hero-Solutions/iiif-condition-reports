<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'damage_cases')]
#[ORM\UniqueConstraint(name: 'uniq_damage_case_client_id', columns: ['report_id', 'client_id'])]
class DamageCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\Column(length: 255)]
    private string $clientId;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(length: 30)]
    private string $color;

    #[ORM\Column(type: Types::JSON)]
    private array $legendGeometry = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $legendNote = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $strokeWidth = 2.2;

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

    public function __construct(Report $report, string $clientId, string $label, string $color = '#d13b3b')
    {
        $now = new \DateTimeImmutable();
        $this->report = $report;
        $this->clientId = trim($clientId);
        $this->label = trim($label);
        $this->color = $this->normalizeColor($color);
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

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label);
        $this->touch();

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $this->normalizeColor($color);
        $this->touch();

        return $this;
    }

    public function getLegendGeometry(): array
    {
        return $this->legendGeometry;
    }

    public function setLegendGeometry(array $legendGeometry): self
    {
        $this->legendGeometry = $legendGeometry;
        $this->touch();

        return $this;
    }

    public function getLegendNote(): ?string
    {
        return $this->legendNote;
    }

    public function setLegendNote(?string $legendNote): self
    {
        $legendNote = trim((string) $legendNote);
        $this->legendNote = $legendNote !== '' ? $legendNote : null;
        $this->touch();

        return $this;
    }

    public function getStrokeWidth(): float
    {
        return $this->strokeWidth;
    }

    public function setStrokeWidth(float $strokeWidth): self
    {
        $this->strokeWidth = max(1.0, min(5.0, $strokeWidth));
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

    private function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : '#d13b3b';
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
