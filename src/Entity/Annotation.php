<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'annotations')]
#[ORM\UniqueConstraint(name: 'uniq_annotation_client_id', columns: ['report_image_id', 'client_id'])]
class Annotation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne(targetEntity: ReportImage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReportImage $reportImage;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $sourceAnnotation = null;

    #[ORM\Column(length: 255)]
    private string $clientId;

    #[ORM\Column(type: Types::JSON)]
    private array $geometry = [];

    #[ORM\Column(type: Types::JSON)]
    private array $body = [];

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $damageType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $deleted = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Report $report, ReportImage $reportImage, string $clientId = '')
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->report = $report;
        $this->reportImage = $reportImage;
        $this->clientId = trim($clientId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getReportImage(): ReportImage
    {
        return $this->reportImage;
    }

    public function getSourceAnnotation(): ?self
    {
        return $this->sourceAnnotation;
    }

    public function setSourceAnnotation(?self $sourceAnnotation): self
    {
        $this->sourceAnnotation = $sourceAnnotation;
        $this->touch();

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): self
    {
        $this->clientId = trim($clientId);
        $this->touch();

        return $this;
    }

    public function getGeometry(): array
    {
        return $this->geometry;
    }

    public function setGeometry(array $geometry): self
    {
        $this->geometry = $geometry;
        $this->touch();

        return $this;
    }

    public function getBody(): array
    {
        return $this->body;
    }

    public function setBody(array $body): self
    {
        $this->body = $body;
        $this->touch();

        return $this;
    }

    public function getDamageType(): ?string
    {
        return $this->damageType;
    }

    public function setDamageType(?string $damageType): self
    {
        $this->damageType = $this->nullableText($damageType);
        $this->touch();

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $this->nullableText($label);
        $this->touch();

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $this->nullableText($color);
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $this->nullableText($notes);
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
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
