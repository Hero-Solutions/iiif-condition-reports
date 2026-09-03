<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

final class EntraAuthenticator extends OAuth2Authenticator
{
    use TargetPathTrait;

    public const NONCE_SESSION_KEY = 'condition_reports.entra_nonce';
    public const LOCALE_SESSION_KEY = 'condition_reports.entra_locale';

    public function __construct(
        private readonly ClientRegistry $clientRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(bool:SSO_ENABLED)%')] private readonly bool $enabled,
        #[Autowire('%env(SSO_ENTRA_TENANT_ID)%')] private readonly string $tenantId,
        #[Autowire('%env(SSO_ENTRA_ROLE_USER)%')] private readonly string $userRole,
        #[Autowire('%env(SSO_ENTRA_ROLE_READ_ONLY)%')] private readonly string $readOnlyRole,
        #[Autowire('%env(SSO_ENTRA_ROLE_ADMIN)%')] private readonly string $adminRole,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'sso_callback';
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->enabled) {
            throw new CustomUserMessageAuthenticationException('login.sso_disabled');
        }

        try {
            $client = $this->clientRegistry->getClient('entra');
            $accessToken = $this->fetchAccessToken($client);
            $resourceOwner = $client->fetchUserFromToken($accessToken);
        } catch (AuthenticationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CustomUserMessageAuthenticationException('login.sso_error', [], 0, $exception);
        }

        if (!$resourceOwner instanceof AzureResourceOwner) {
            throw new CustomUserMessageAuthenticationException('login.sso_error');
        }

        $expectedNonce = (string) $request->getSession()->remove(self::NONCE_SESSION_KEY);
        $receivedNonce = (string) $resourceOwner->claim('nonce');

        if ($expectedNonce === '' || $receivedNonce === '' || !hash_equals($expectedNonce, $receivedNonce)) {
            throw new CustomUserMessageAuthenticationException('login.sso_error');
        }

        $objectId = trim((string) $resourceOwner->getId());
        $tenantId = trim((string) $resourceOwner->getTenantId());
        $email = $this->resolveEmail($resourceOwner);
        $fullName = $this->resolveFullName($resourceOwner, $email);
        $roles = $this->resolveRoles($resourceOwner);

        if ($objectId === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CustomUserMessageAuthenticationException('login.sso_missing_identity');
        }

        if ($this->tenantId === '' || !hash_equals(mb_strtolower($this->tenantId), mb_strtolower($tenantId))) {
            throw new CustomUserMessageAuthenticationException('login.sso_wrong_tenant');
        }

        if ($roles === []) {
            throw new CustomUserMessageAuthenticationException('login.sso_not_authorized');
        }

        return new SelfValidatingPassport(new UserBadge(
            $objectId,
            fn (): User => $this->synchronizeUser($objectId, $email, $fullName, $roles),
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);

        if (is_string($targetPath) && $targetPath !== '') {
            return new RedirectResponse($targetPath);
        }

        $locale = $this->getLocale($request);

        return new RedirectResponse($this->urlGenerator->generate('projects_index', ['_locale' => $locale]));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'login.sso_error';

        $request->getSession()->getFlashBag()->add('error', $message);

        return new RedirectResponse($this->urlGenerator->generate('app_login', [
            '_locale' => $this->getLocale($request),
        ]));
    }

    /** @param list<string> $roles */
    private function synchronizeUser(string $objectId, string $email, string $fullName, array $roles): User
    {
        $repository = $this->entityManager->getRepository(User::class);
        $userByObjectId = $repository->findOneBy(['entraObjectId' => $objectId]);
        $userByEmail = $repository->findOneBy(['email' => $email]);

        if ($userByObjectId instanceof User && $userByEmail instanceof User && $userByObjectId !== $userByEmail) {
            throw new CustomUserMessageAuthenticationException('login.sso_email_conflict');
        }

        $user = $userByObjectId instanceof User ? $userByObjectId : $userByEmail;

        if (!$user instanceof User) {
            $user = new User();
        } elseif ($user->getEntraObjectId() !== null && $user->getEntraObjectId() !== $objectId) {
            throw new CustomUserMessageAuthenticationException('login.sso_email_conflict');
        }

        if ($user->getEntraObjectId() === null) {
            $randomPassword = bin2hex(random_bytes(32));
            $user->setPassword($this->passwordHasher->hashPassword($user, $randomPassword));
        }

        $user
            ->setEntraObjectId($objectId)
            ->setEmail(mb_substr(mb_strtolower($email), 0, 180))
            ->setFullName(mb_substr($fullName, 0, 255))
            ->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function resolveEmail(AzureResourceOwner $resourceOwner): string
    {
        foreach ([$resourceOwner->getEmail(), $resourceOwner->getPreferredUsername(), $resourceOwner->getUpn()] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function resolveFullName(AzureResourceOwner $resourceOwner, string $email): string
    {
        $name = trim((string) $resourceOwner->claim('name'));

        if ($name !== '') {
            return $name;
        }

        $name = trim(implode(' ', array_filter([
            trim((string) $resourceOwner->getFirstName()),
            trim((string) $resourceOwner->getLastName()),
        ])));

        return $name !== '' ? $name : $email;
    }

    /** @return list<string> */
    private function resolveRoles(AzureResourceOwner $resourceOwner): array
    {
        $entraRoles = $resourceOwner->claim('roles');
        $entraRoles = is_array($entraRoles) ? array_map('strval', $entraRoles) : [];

        if ($this->adminRole !== '' && in_array($this->adminRole, $entraRoles, true)) {
            return [User::ROLE_USER, User::ROLE_ADMIN];
        }

        if ($this->readOnlyRole !== '' && in_array($this->readOnlyRole, $entraRoles, true)) {
            return [User::ROLE_USER, User::ROLE_READ_ONLY];
        }

        if ($this->userRole !== '' && in_array($this->userRole, $entraRoles, true)) {
            return [User::ROLE_USER];
        }

        return [];
    }

    private function getLocale(Request $request): string
    {
        $locale = (string) $request->getSession()->remove(self::LOCALE_SESSION_KEY);

        return in_array($locale, ['nl', 'en'], true) ? $locale : 'nl';
    }
}
