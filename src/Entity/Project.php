<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Table(name: 'projects')]
class Project
{
    public const TYPE_LOAN = 'loan';
    public const TYPE_EXHIBITION = 'exhibition';
    public const TYPE_RESTORATION = 'restoration';
    public const TYPE_RESEARCH = 'research';
    public const TYPE_MOVEMENT = 'movement';
    public const TYPE_CONSERVATION = 'conservation';
    public const TYPE_OTHER = 'other';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const TYPES = [
        self::TYPE_LOAN,
        self::TYPE_EXHIBITION,
        self::TYPE_RESTORATION,
        self::TYPE_RESEARCH,
        self::TYPE_MOVEMENT,
        self::TYPE_CONSERVATION,
        self::TYPE_OTHER,
    ];

    private const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_OTHER;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customType = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referenceCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title = '', string $type = self::TYPE_OTHER)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->title = $title;
        $this->setType($type);
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $this->choice($type, self::TYPES, self::TYPE_OTHER);

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

    public function setCustomType(?string $customType): self
    {
        $this->customType = $this->nullableText($customType);
        $this->touch();

        return $this;
    }

    public function normalizeCustomType(): self
    {
        if ($this->type !== self::TYPE_OTHER) {
            $this->customType = null;
        }

        return $this;
    }

    #[Assert\Callback]
    public function validateCustomType(ExecutionContextInterface $context): void
    {
        if ($this->type === self::TYPE_OTHER && $this->customType === null) {
            $context
                ->buildViolation('projects.required_custom_type')
                ->atPath('customType')
                ->addViolation();
        }
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $this->choice($status, self::STATUSES, self::STATUS_ACTIVE);
        $this->touch();

        return $this;
    }

    public function getReferenceCode(): ?string
    {
        return $this->referenceCode;
    }

    public function setReferenceCode(?string $referenceCode): self
    {
        $this->referenceCode = $this->nullableText($referenceCode);
        $this->touch();

        return $this;
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

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;
        $this->touch();

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;
        $this->touch();

        return $this;
    }

    public function setPeriod(?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): self
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
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

    private function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
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
