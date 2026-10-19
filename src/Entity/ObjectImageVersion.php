<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'object_image_versions')]
class ObjectImageVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ObjectRecord $objectRecord;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(type: Types::JSON)]
    private array $source;

    #[ORM\Column(type: Types::JSON)]
    private array $images;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $replacedAt = null;

    public function __construct(ObjectRecord $object, string $fingerprint, array $source, array $images)
    {
        $this->objectRecord = $object;
        $this->fingerprint = $fingerprint;
        $this->source = $source;
        $this->images = $images;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getObjectRecord(): ObjectRecord { return $this->objectRecord; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getSource(): array { return $this->source; }
    public function getImages(): array { return $this->images; }
    public function setImages(array $images): void { $this->images = $images; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReplacedAt(): ?\DateTimeImmutable { return $this->replacedAt; }

    public function markReplaced(\DateTimeImmutable $date): void
    {
        $this->replacedAt ??= $date;
    }
}
