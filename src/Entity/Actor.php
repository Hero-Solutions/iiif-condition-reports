<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'actors')]
#[ORM\UniqueConstraint(name: 'uniq_actor_name', columns: ['name'])]
class Actor
{
    public const TYPE_ORGANIZATION = 'organization';
    public const TYPE_PERSON = 'person';

    private const TYPES = [
        self::TYPE_ORGANIZATION,
        self::TYPE_PERSON,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_ORGANIZATION;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alias = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vat = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $postal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stateProvince = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mobile = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name = '', string $type = self::TYPE_ORGANIZATION)
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->name = trim($name);
        $this->setType($type);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = mb_substr(trim($name), 0, 255);
        $this->touch();

        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->alias ?? $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = in_array($type, self::TYPES, true) ? $type : self::TYPE_ORGANIZATION;
        $this->touch();

        return $this;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    public function setAlias(?string $alias): self
    {
        $this->alias = $this->nullableText($alias, 255);
        $this->touch();

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $this->nullableText($logo, 255);
        $this->touch();

        return $this;
    }

    public function getVat(): ?string
    {
        return $this->vat;
    }

    public function setVat(?string $vat): self
    {
        $this->vat = $this->nullableText($vat, 255);
        $this->touch();

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $this->nullableText($address, 255);
        $this->touch();

        return $this;
    }

    public function getPostal(): ?string
    {
        return $this->postal;
    }

    public function setPostal(?string $postal): self
    {
        $this->postal = $this->nullableText($postal, 255);
        $this->touch();

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $this->nullableText($city, 255);
        $this->touch();

        return $this;
    }

    public function getStateProvince(): ?string
    {
        return $this->stateProvince;
    }

    public function setStateProvince(?string $stateProvince): self
    {
        $this->stateProvince = $this->nullableText($stateProvince, 255);
        $this->touch();

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $this->nullableText($country, 255);
        $this->touch();

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $this->nullableText($email, 180);
        $this->touch();

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $this->nullableText($website, 255);
        $this->touch();

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $this->nullableText($phone, 100);
        $this->touch();

        return $this;
    }

    public function getMobile(): ?string
    {
        return $this->mobile;
    }

    public function setMobile(?string $mobile): self
    {
        $this->mobile = $this->nullableText($mobile, 100);
        $this->touch();

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $this->nullableText($notes);
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function nullableText(?string $value, ?int $maxLength = null): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        return $maxLength === null ? $value : mb_substr($value, 0, $maxLength);
    }

    /**
     * @return array<string, string>
     */
    public static function typeChoices(): array
    {
        return [
            'actor_type.organization' => self::TYPE_ORGANIZATION,
            'actor_type.person' => self::TYPE_PERSON,
        ];
    }
}
