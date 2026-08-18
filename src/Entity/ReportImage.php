<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_images')]
#[ORM\Index(name: 'idx_report_image_hash', columns: ['hash'])]
class ReportImage
{
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_IIIF = 'iiif';
    public const SOURCE_DATAHUB = 'datahub';
    public const SOURCE_SCHEMA = 'schema';

    private const SOURCES = [
        self::SOURCE_UPLOAD,
        self::SOURCE_IIIF,
        self::SOURCE_DATAHUB,
        self::SOURCE_SCHEMA,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne(targetEntity: ObjectRecord::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ObjectRecord $objectRecord = null;

    #[ORM\Column(length: 50)]
    private string $source = self::SOURCE_UPLOAD;

    #[ORM\Column(type: Types::TEXT)]
    private string $path;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thumbnailPath = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Report $report, string $path = '', string $source = self::SOURCE_UPLOAD)
    {
        $this->report = $report;
        $this->setPath($path);
        $this->setSource($source);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getObjectRecord(): ?ObjectRecord
    {
        return $this->objectRecord;
    }

    public function setObjectRecord(?ObjectRecord $objectRecord): self
    {
        $this->objectRecord = $objectRecord;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : self::SOURCE_UPLOAD;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = trim($path);
        $this->hash = $this->path === '' ? null : hash('sha256', $this->path);

        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): self
    {
        $this->thumbnailPath = $this->nullableText($thumbnailPath);

        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $this->originalName = $this->nullableText($originalName);

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $this->nullableText($mimeType);

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = max(0, $sortOrder);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
