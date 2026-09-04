<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function __construct(
        #[Autowire('%env(bool:LOCAL_LOGIN_ENABLED)%')] private readonly bool $localLoginEnabled,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$this->localLoginEnabled && !$user->isSsoManaged()) {
            throw new CustomUserMessageAuthenticationException('login.local_disabled');
        }

        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAuthenticationException('login.inactive_user');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
