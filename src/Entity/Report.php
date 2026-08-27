<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reports')]
#[ORM\Index(name: 'idx_report_type', columns: ['type'])]
#[ORM\Index(name: 'idx_report_status', columns: ['status'])]
class Report
{
    public const TYPE_INCOMING_CONDITION = 'incoming_condition';
    public const TYPE_OUTGOING_CONDITION = 'outgoing_condition';
    public const TYPE_RETURNING_CONDITION = 'returning_condition';
    public const TYPE_QUICK_CHECK = 'quick_condition_check';
    public const TYPE_RESTORATION = 'restoration';
    public const TYPE_RESEARCH = 'research';
    public const TYPE_DAMAGE_CLAIM = 'damage_claim';
    public const TYPE_MOVEMENT = 'movement';
    public const TYPE_OTHER = 'other';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_ARCHIVED = 'archived';

    private const TYPES = [
        self::TYPE_INCOMING_CONDITION,
        self::TYPE_OUTGOING_CONDITION,
        self::TYPE_RETURNING_CONDITION,
        self::TYPE_QUICK_CHECK,
        self::TYPE_RESTORATION,
        self::TYPE_RESEARCH,
        self::TYPE_DAMAGE_CLAIM,
        self::TYPE_MOVEMENT,
        self::TYPE_OTHER,
    ];

    private const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_FINALIZED,
        self::STATUS_ARCHIVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ReportSeries::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReportSeries $series;

    #[ORM\ManyToOne(targetEntity: ObjectRecord::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectRecord $objectRecord;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $basedOnReport = null;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_OTHER;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customType = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customReason = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $receiptAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $createdById = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $finalizedById = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ReportSeries $series, string $type = self::TYPE_OTHER)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->series = $series;
        $this->objectRecord = $series->getObjectRecord();
        $this->project = $series->getProject();
        $this->setType($type);
        $this->title = str_replace('_', ' ', $this->type);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeries(): ReportSeries
    {
        return $this->series;
    }

    public function setSeries(ReportSeries $series): self
    {
        $this->ensureEditable();

        if ($series->getObjectRecord() !== $this->objectRecord) {
            throw new \InvalidArgumentException('A report can only be moved to a series for the same object.');
        }

        $this->series = $series;
        $this->project = $series->getProject();
        $this->touch();

        return $this;
    }

    public function getObjectRecord(): ObjectRecord
    {
        return $this->objectRecord;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function getBasedOnReport(): ?self
    {
        return $this->basedOnReport;
    }

    public function setBasedOnReport(?self $basedOnReport): self
    {
        $this->ensureEditable();
        $this->basedOnReport = $basedOnReport;
        $this->touch();

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->ensureEditable();
        $this->type = in_array($type, self::TYPES, true) ? $type : self::TYPE_OTHER;

        if ($this->type !== self::TYPE_OTHER) {
            $this->customType = null;
        }

        $this->touch();

        return $this;
    }

    public function getCustomType(): ?string
    {
        return $this->customType;
    }

    public function hasSelectedType(): bool
    {
        return $this->type !== self::TYPE_OTHER || $this->customType !== null;
    }

    public function setCustomType(?string $customType): self
    {
        $this->ensureEditable();
        $customType = $customType === null ? null : trim($customType);
        $this->customType = $this->type === self::TYPE_OTHER && $customType !== ''
            ? mb_substr($customType, 0, 100)
            : null;
        $this->touch();

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return self::TYPES;
    }

    /**
     * @return array<string, string>
     */
    public static function typeChoices(): array
    {
        return [
            'report_type.incoming_condition' => self::TYPE_INCOMING_CONDITION,
            'report_type.outgoing_condition' => self::TYPE_OUTGOING_CONDITION,
            'report_type.returning_condition' => self::TYPE_RETURNING_CONDITION,
            'report_type.quick_condition_check' => self::TYPE_QUICK_CHECK,
            'report_type.restoration' => self::TYPE_RESTORATION,
            'report_type.research' => self::TYPE_RESEARCH,
            'report_type.damage_claim' => self::TYPE_DAMAGE_CLAIM,
            'report_type.movement' => self::TYPE_MOVEMENT,
            'report_type.other' => self::TYPE_OTHER,
        ];
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->ensureEditable();
        $this->title = trim($title);
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->ensureEditable();
        $description = $description === null ? '' : trim($description);
        $this->description = $description === '' ? null : $description;
        $this->touch();

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->ensureEditable();
        $this->reason = $this->nullableText($reason, 50);

        if ($this->reason !== 'other') {
            $this->customReason = null;
        }

        $this->touch();

        return $this;
    }

    public function getCustomReason(): ?string
    {
        return $this->customReason;
    }

    public function setCustomReason(?string $customReason): self
    {
        $this->ensureEditable();
        $this->customReason = $this->reason === 'other'
            ? $this->nullableText($customReason, 255)
            : null;
        $this->touch();

        return $this;
    }

    public function getReceiptAt(): ?\DateTimeImmutable
    {
        return $this->receiptAt;
    }

    public function setReceiptAt(?\DateTimeImmutable $receiptAt): self
    {
        $this->ensureEditable();
        $this->receiptAt = $receiptAt;
        $this->touch();

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->ensureEditable();
        $this->startedAt = $startedAt;
        $this->touch();

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->ensureEditable();
        $this->endedAt = $endedAt;
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
            'status.active' => self::STATUS_ACTIVE,
            'status.finalized' => self::STATUS_FINALIZED,
            'status.archived' => self::STATUS_ARCHIVED,
        ];
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->ensureEditable();
        $this->data = $data;
        $this->touch();

        return $this;
    }

    public function getValue(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function setValue(string $key, mixed $value): self
    {
        $this->ensureEditable();
        $key = trim($key);

        if ($key !== '') {
            $this->data[$key] = $value;
            $this->touch();
        }

        return $this;
    }

    public function getCreatedById(): ?int
    {
        return $this->createdById;
    }

    public function setCreatedById(?int $createdById): self
    {
        $this->ensureEditable();
        $this->createdById = $createdById;
        $this->touch();

        return $this;
    }

    public function getFinalizedAt(): ?\DateTimeImmutable
    {
        return $this->finalizedAt;
    }

    public function getFinalizedById(): ?int
    {
        return $this->finalizedById;
    }

    public function finalize(?int $finalizedById, ?\DateTimeImmutable $finalizedAt = null): self
    {
        $this->ensureEditable();
        $this->status = self::STATUS_FINALIZED;
        $this->finalizedAt = $finalizedAt ?? new \DateTimeImmutable();
        $this->finalizedById = $finalizedById;
        $this->touch();

        return $this;
    }

    public function archive(): self
    {
        if ($this->status !== self::STATUS_FINALIZED) {
            throw new \LogicException('Only a finalized report can be archived.');
        }

        $this->status = self::STATUS_ARCHIVED;
        $this->touch();

        return $this;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getVersion(): int
    {
        return $this->version;
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

    private function ensureEditable(): void
    {
        if (!$this->isEditable()) {
            throw new \LogicException('A finalized or archived report cannot be changed.');
        }
    }

    private function nullableText(?string $value, int $maxLength): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
