<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'object_records')]
#[ORM\UniqueConstraint(name: 'uniq_object_inventory_number', columns: ['inventory_number'])]
#[ORM\Index(name: 'idx_object_title', columns: ['title'])]
class ObjectRecord
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DATAHUB = 'datahub';
    public const SOURCE_TMS = 'tms';
    public const SOURCE_IMPORT = 'import';

    public const SYNC_NOT_SYNCED = 'not_synced';
    public const SYNC_SYNCED = 'synced';
    public const SYNC_LOCAL_CHANGES = 'local_changes';
    public const SYNC_ERROR = 'sync_error';

    public const TYPE_PAINTING = 'painting';
    public const TYPE_WORK_ON_PAPER = 'work_on_paper';
    public const TYPE_SCULPTURE = 'sculpture';
    public const TYPE_OTHER = 'other';

    private const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_DATAHUB,
        self::SOURCE_TMS,
        self::SOURCE_IMPORT,
    ];

    private const SYNC_STATUSES = [
        self::SYNC_NOT_SYNCED,
        self::SYNC_SYNCED,
        self::SYNC_LOCAL_CHANGES,
        self::SYNC_ERROR,
    ];

    private const OBJECT_TYPES = [
        self::TYPE_PAINTING,
        self::TYPE_WORK_ON_PAPER,
        self::TYPE_SCULPTURE,
        self::TYPE_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $inventoryNumber;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $creator = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $objectType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customObjectType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $currentLocation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $externalImageUrl = null;

    #[ORM\Column(length: 50)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 50)]
    private string $syncStatus = self::SYNC_NOT_SYNCED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: Types::JSON)]
    private array $sourceData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $inventoryNumber = '', string $title = '')
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->inventoryNumber = trim($inventoryNumber);
        $this->title = trim($title);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInventoryNumber(): string
    {
        return $this->inventoryNumber;
    }

    public function setInventoryNumber(string $inventoryNumber): self
    {
        $this->inventoryNumber = trim($inventoryNumber);
        $this->markLocalChange();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDisplayTitle(string $locale = 'nl'): string
    {
        if ($locale === 'en') {
            $titleEn = $this->sourceData['title_en'] ?? null;

            if (is_string($titleEn) && trim($titleEn) !== '') {
                return trim($titleEn);
            }
        }

        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);
        $this->markLocalChange();

        return $this;
    }

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): self
    {
        $this->creator = $this->nullableText($creator);
        $this->markLocalChange();

        return $this;
    }

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(?string $publisher): self
    {
        $this->publisher = $this->nullableText($publisher);
        $this->markLocalChange();

        return $this;
    }

    public function getObjectType(): ?string
    {
        return self::normalizeObjectType($this->objectType);
    }

    public function setObjectType(?string $objectType): self
    {
        $this->objectType = self::normalizeObjectType($objectType);

        if ($this->objectType !== self::TYPE_OTHER) {
            $this->customObjectType = null;
        }

        $this->markLocalChange();

        return $this;
    }

    public function getCustomObjectType(): ?string
    {
        return $this->customObjectType;
    }

    public function setCustomObjectType(?string $customObjectType): self
    {
        $this->customObjectType = $this->objectType === self::TYPE_OTHER
            ? $this->shortText($customObjectType, 100)
            : null;
        $this->markLocalChange();

        return $this;
    }

    public function getCurrentLocation(): ?string
    {
        return $this->currentLocation;
    }

    public function setCurrentLocation(?string $currentLocation): self
    {
        $this->currentLocation = $this->nullableText($currentLocation);
        $this->markLocalChange();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $this->nullableText($description);
        $this->markLocalChange();

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $this->nullableText($imageUrl);
        $this->markLocalChange();

        return $this;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): self
    {
        $this->thumbnailUrl = $this->nullableText($thumbnailUrl);
        $this->markLocalChange();

        return $this;
    }

    public function getExternalImageUrl(): ?string
    {
        return $this->externalImageUrl;
    }

    public function setExternalImageUrl(?string $externalImageUrl): self
    {
        $this->externalImageUrl = $this->nullableText($externalImageUrl);
        $this->markLocalChange();

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source, ?string $externalId = null): self
    {
        $this->source = $this->choice($source, self::SOURCES, self::SOURCE_MANUAL);
        $this->externalId = $this->nullableText($externalId);
        $this->touch();

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getSyncStatus(): string
    {
        return $this->syncStatus;
    }

    public function setSyncStatus(string $syncStatus): self
    {
        $this->syncStatus = $this->choice($syncStatus, self::SYNC_STATUSES, self::SYNC_NOT_SYNCED);
        $this->touch();

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function markSynced(?\DateTimeImmutable $syncedAt = null): self
    {
        $this->syncStatus = self::SYNC_SYNCED;
        $this->lastSyncedAt = $syncedAt ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function applyDatahubSync(
        string $inventoryNumber,
        string $externalId,
        array $sourceData,
        ?string $title = null,
        ?string $creator = null,
        ?string $publisher = null,
        ?string $objectType = null,
        ?string $description = null,
        ?string $currentLocation = null,
        ?\DateTimeImmutable $syncedAt = null,
    ): self {
        $this->inventoryNumber = mb_substr(trim($inventoryNumber), 0, 100);
        $this->title = $this->shortText($title, 255) ?? $this->inventoryNumber;
        $this->creator = $this->nullableText($creator);
        $this->publisher = $this->shortText($publisher, 255);
        $this->objectType = self::normalizeObjectType($objectType);
        $this->customObjectType = null;
        $this->currentLocation = $this->shortText($currentLocation, 255);
        $this->description = $this->nullableText($description);
        $this->source = self::SOURCE_DATAHUB;
        $this->externalId = $this->shortText($externalId, 255);
        $this->sourceData = $sourceData;
        $this->syncStatus = self::SYNC_SYNCED;
        $this->lastSyncedAt = $syncedAt ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getSourceData(): array
    {
        return $this->sourceData;
    }

    public function setSourceData(array $sourceData): self
    {
        $this->sourceData = $sourceData;
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

    /**
     * @return list<string>
     */
    public static function objectTypes(): array
    {
        return self::OBJECT_TYPES;
    }

    /**
     * @return array<string, string>
     */
    public static function objectTypeChoices(): array
    {
        return [
            'object_type.painting' => self::TYPE_PAINTING,
            'object_type.work_on_paper' => self::TYPE_WORK_ON_PAPER,
            'object_type.sculpture' => self::TYPE_SCULPTURE,
            'object_type.other' => self::TYPE_OTHER,
        ];
    }

    public static function normalizeObjectType(?string $objectType): ?string
    {
        $objectType = $objectType === null ? null : trim($objectType);

        if ($objectType === null || $objectType === '') {
            return null;
        }

        return in_array($objectType, self::OBJECT_TYPES, true) ? $objectType : null;
    }

    public static function guessObjectTypeFromDatahub(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $value = $value === null ? '' : mb_strtolower(trim($value));

            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'schilder') || str_contains($value, 'doek') || str_contains($value, 'paneel')) {
                return self::TYPE_PAINTING;
            }

            if (str_contains($value, 'papier') || str_contains($value, 'tekening')) {
                return self::TYPE_WORK_ON_PAPER;
            }

            if (str_contains($value, 'beeld')) {
                return self::TYPE_SCULPTURE;
            }
        }

        return null;
    }

    private function markLocalChange(): void
    {
        if ($this->source !== self::SOURCE_MANUAL && $this->syncStatus === self::SYNC_SYNCED) {
            $this->syncStatus = self::SYNC_LOCAL_CHANGES;
        }

        $this->touch();
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

    private function shortText(?string $value, int $maxLength): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : mb_substr($value, 0, $maxLength);
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
