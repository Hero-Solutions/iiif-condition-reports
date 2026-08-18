<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'iiif_manifests')]
#[ORM\UniqueConstraint(name: 'uniq_iiif_manifest_id', columns: ['manifest_id'])]
class IIIFManifest
{
    public const SOURCE_DATAHUB = 'datahub';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_GENERATED = 'generated';
    public const SOURCE_IMPORT = 'import';

    private const SOURCES = [
        self::SOURCE_DATAHUB,
        self::SOURCE_MANUAL,
        self::SOURCE_GENERATED,
        self::SOURCE_IMPORT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $manifestId = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 50)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManifestId(): string
    {
        return $this->manifestId;
    }

    public function setManifestId(string $manifestId): self
    {
        $this->manifestId = trim($manifestId);
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_MANUAL;
        $this->touch();

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $this->nullableText($sourceUrl);
        $this->touch();

        return $this;
    }

    public function getThumbnailUrl(): ?string
    {
        return $this->thumbnailUrl;
    }

    public function setThumbnailUrl(?string $thumbnailUrl): self
    {
        $this->thumbnailUrl = $this->nullableText($thumbnailUrl);
        $this->touch();

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
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
