<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'object_manifests')]
#[ORM\UniqueConstraint(name: 'uniq_object_manifest', columns: ['object_record_id', 'manifest_id'])]
class ObjectManifest
{
    public const ROLE_SOURCE = 'source';
    public const ROLE_REFERENCE = 'reference';
    public const ROLE_GENERATED = 'generated';

    private const ROLES = [
        self::ROLE_SOURCE,
        self::ROLE_REFERENCE,
        self::ROLE_GENERATED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ObjectRecord::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectRecord $objectRecord;

    #[ORM\ManyToOne(targetEntity: IIIFManifest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IIIFManifest $manifest;

    #[ORM\Column(length: 50)]
    private string $role = self::ROLE_SOURCE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ObjectRecord $objectRecord, IIIFManifest $manifest, string $role = self::ROLE_SOURCE)
    {
        $this->objectRecord = $objectRecord;
        $this->manifest = $manifest;
        $this->setRole($role);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObjectRecord(): ObjectRecord
    {
        return $this->objectRecord;
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
        $this->role = in_array($role, self::ROLES, true) ? $role : self::ROLE_SOURCE;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
