<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'organization_contacts')]
#[ORM\UniqueConstraint(name: 'uniq_organization_contact', columns: ['organization_id', 'person_id'])]
#[ORM\Index(name: 'idx_organization_contact_organization', columns: ['organization_id'])]
#[ORM\Index(name: 'idx_organization_contact_person', columns: ['person_id'])]
class OrganizationContact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Actor::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Actor $organization;

    #[ORM\ManyToOne(targetEntity: Actor::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Actor $person = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $functionTitle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Actor $organization, Actor|string|null $person = null)
    {
        if ($organization->getType() !== Actor::TYPE_ORGANIZATION) {
            throw new \InvalidArgumentException('An organization contact must belong to an organization.');
        }

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->organization = $organization;

        if ($person !== null) {
            $this->setPerson(is_string($person) ? new Actor($person, Actor::TYPE_PERSON) : $person);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Actor
    {
        return $this->organization;
    }

    public function getActor(): Actor
    {
        return $this->organization;
    }

    public function getPerson(): ?Actor
    {
        return $this->person;
    }

    public function setPerson(Actor $person): self
    {
        if ($person->getType() !== Actor::TYPE_PERSON) {
            throw new \InvalidArgumentException('An organization contact must be a person.');
        }

        $this->person = $person;
        $this->touch();

        return $this;
    }

    public function getFunctionTitle(): ?string
    {
        return $this->functionTitle;
    }

    public function setFunctionTitle(?string $functionTitle): self
    {
        $this->functionTitle = $this->nullableText($functionTitle, 255);
        $this->touch();

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->person?->getDisplayName() ?? '';
    }

    public function getName(): string
    {
        return $this->person?->getName() ?? '';
    }

    public function setName(string $name): self
    {
        $this->person()->setName($name);
        $this->touch();

        return $this;
    }

    public function getAlias(): ?string
    {
        return $this->person?->getAlias();
    }

    public function setAlias(?string $alias): self
    {
        $this->person()->setAlias($alias);
        $this->touch();

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->person?->getEmail();
    }

    public function setEmail(?string $email): self
    {
        $this->person()->setEmail($email);
        $this->touch();

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->person?->getPhone();
    }

    public function setPhone(?string $phone): self
    {
        $this->person()->setPhone($phone);
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->person?->getNotes();
    }

    public function setNotes(?string $notes): self
    {
        $this->person()->setNotes($notes);
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

    private function nullableText(?string $value, int $maxLength): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function person(): Actor
    {
        if (!$this->person instanceof Actor) {
            throw new \LogicException('No person has been selected.');
        }

        return $this->person;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
