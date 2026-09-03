<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_READ_ONLY = 'ROLE_READ_ONLY';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $fullName = '';

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [self::ROLE_USER];

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 36, unique: true, nullable: true)]
    private ?string $entraObjectId = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));
        $this->touch();

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): self
    {
        $this->fullName = trim($fullName);
        $this->touch();

        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;

        return array_values(array_unique($roles));
    }

    public function setRoles(array $roles): self
    {
        $allowed = [self::ROLE_USER, self::ROLE_ADMIN, self::ROLE_READ_ONLY];
        $roles = array_values(array_intersect($roles, $allowed));

        if ($roles === []) {
            $roles = [self::ROLE_USER];
        }

        $this->roles = $roles;
        $this->touch();

        return $this;
    }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->roles, true);
    }

    public function isReadOnly(): bool
    {
        return in_array(self::ROLE_READ_ONLY, $this->roles, true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        $this->touch();

        return $this;
    }

    public function getEntraObjectId(): ?string
    {
        return $this->entraObjectId;
    }

    public function setEntraObjectId(?string $entraObjectId): self
    {
        $entraObjectId = $entraObjectId !== null ? trim($entraObjectId) : null;
        $this->entraObjectId = $entraObjectId !== '' ? $entraObjectId : null;
        $this->touch();

        return $this;
    }

    public function isSsoManaged(): bool
    {
        return $this->entraObjectId !== null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
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

    public function eraseCredentials(): void
    {
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
