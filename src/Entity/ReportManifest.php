<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_manifests')]
#[ORM\UniqueConstraint(name: 'uniq_report_manifest', columns: ['report_id', 'manifest_id'])]
class ReportManifest
{
    public const ROLE_REFERENCE = 'reference';
    public const ROLE_GENERATED = 'generated';
    public const ROLE_EXPORT = 'export';

    private const ROLES = [
        self::ROLE_REFERENCE,
        self::ROLE_GENERATED,
        self::ROLE_EXPORT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Report::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Report $report;

    #[ORM\ManyToOne(targetEntity: IIIFManifest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IIIFManifest $manifest;

    #[ORM\Column(length: 50)]
    private string $role = self::ROLE_REFERENCE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Report $report, IIIFManifest $manifest, string $role = self::ROLE_REFERENCE)
    {
        $this->report = $report;
        $this->manifest = $manifest;
        $this->setRole($role);
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

    public function getManifest(): IIIFManifest
    {
        return $this->manifest;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = in_array($role, self::ROLES, true) ? $role : self::ROLE_REFERENCE;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
