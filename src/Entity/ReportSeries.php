<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_series')]
#[ORM\Index(name: 'idx_report_series_status', columns: ['status'])]
class ReportSeries
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ObjectRecord::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectRecord $objectRecord;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ObjectRecord $objectRecord, string $title = '', ?Project $project = null)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->objectRecord = $objectRecord;
        $this->project = $project;
        $this->title = trim($title);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjectRecord(): ObjectRecord
    {
        return $this->objectRecord;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : self::STATUS_ACTIVE;
        $this->touch();

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /**
     * @return array<string, string>
     */
    public static function statusChoices(): array
    {
        return [
            'series_status.active' => self::STATUS_ACTIVE,
            'series_status.completed' => self::STATUS_COMPLETED,
            'series_status.cancelled' => self::STATUS_CANCELLED,
        ];
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $this->nullableText($description);
        $this->touch();

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        $this->touch();

        return $this;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;
        $this->touch();

        return $this;
    }

    public function setPeriod(?\DateTimeImmutable $startedAt, ?\DateTimeImmutable $endedAt): self
    {
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
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
