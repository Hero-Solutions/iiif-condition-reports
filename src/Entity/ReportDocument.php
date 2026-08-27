<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_documents')]
class ReportDocument
{
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_SETUP_PLAN = 'setup_plan';
    public const CATEGORY_RECOMMENDATION = 'recommendation';
    public const CATEGORY_EXTERNAL_REPORT = 'external_report';

    private const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_SETUP_PLAN,
        self::CATEGORY_RECOMMENDATION,
        self::CATEGORY_EXTERNAL_REPORT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\Column(length: 50)]
    private string $category = self::CATEGORY_GENERAL;

    #[ORM\Column(type: Types::TEXT)]
    private string $path;

    #[ORM\Column(length: 255)]
    private string $originalName;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column(type: Types::INTEGER)]
    private int $size = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Report $report, string $path, string $originalName, string $mimeType)
    {
        $this->report = $report;
        $this->path = trim($path);
        $this->originalName = mb_substr(trim($originalName), 0, 255);
        $this->mimeType = mb_substr(trim($mimeType), 0, 100);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getReport(): Report { return $this->report; }
    public function getCategory(): string { return $this->category; }
    public function getPath(): string { return $this->path; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSize(): int { return $this->size; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function setCategory(string $category): self
    {
        $this->category = in_array($category, self::CATEGORIES, true) ? $category : self::CATEGORY_GENERAL;

        return $this;
    }

    public function setSize(int $size): self
    {
        $this->size = max(0, $size);

        return $this;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = max(0, $sortOrder);

        return $this;
    }

    /** @return array<string, string> */
    public static function categoryChoices(): array
    {
        return [
            'document_category.general' => self::CATEGORY_GENERAL,
            'document_category.setup_plan' => self::CATEGORY_SETUP_PLAN,
            'document_category.recommendation' => self::CATEGORY_RECOMMENDATION,
            'document_category.external_report' => self::CATEGORY_EXTERNAL_REPORT,
        ];
    }
}
