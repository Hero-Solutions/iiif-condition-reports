<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'object_log_entries')]
#[ORM\Index(name: 'idx_object_log_date', columns: ['object_record_id', 'created_at', 'id'])]
class ObjectLogEntry
{
    public const MANUAL_CREATE = 'manual_create';
    public const MANUAL_UPDATE = 'manual_update';
    public const DATAHUB_SYNC = 'datahub_sync';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ObjectRecord::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectRecord $objectRecord;

    #[ORM\Column(length: 30)]
    private string $type;

    // Keep the identity as it was when the change was made, even after a user is renamed/deleted.
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $userId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userName;

    #[ORM\Column(type: Types::JSON)]
    private array $changes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, array{old: ?string, new: ?string}> $changes */
    public function __construct(ObjectRecord $object, string $type, array $changes, ?User $user = null, ?\DateTimeImmutable $createdAt = null)
    {
        if ($type !== self::DATAHUB_SYNC && $user === null) {
            throw new \InvalidArgumentException('A manual object change requires a user.');
        }

        $this->objectRecord = $object;
        $this->type = $type;
        $this->changes = $changes;
        $this->userId = $user?->getId();
        $this->userName = $user === null ? null : ($user->getFullName() ?: $user->getUserIdentifier());
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getObjectRecord(): ObjectRecord { return $this->objectRecord; }
    public function getType(): string { return $this->type; }
    public function getUserId(): ?int { return $this->userId; }
    public function getUserName(): ?string { return $this->userName; }
    public function getChanges(): array { return $this->changes; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
