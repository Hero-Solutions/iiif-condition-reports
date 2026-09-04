<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\EntraAuthenticator;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class AuthController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(bool:SSO_ENABLED)%')] private readonly bool $ssoEnabled,
        #[Autowire('%env(bool:LOCAL_LOGIN_ENABLED)%')] private readonly bool $localLoginEnabled,
    ) {
    }

    #[Route('/{_locale<nl|en>}/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'sso_enabled' => $this->ssoEnabled,
            'local_login_enabled' => $this->localLoginEnabled,
        ]);
    }

    #[Route('/{_locale<nl|en>}/sso/login', name: 'sso_login', methods: ['GET'])]
    public function ssoLogin(Request $request, ClientRegistry $clientRegistry): Response
    {
        if (!$this->ssoEnabled) {
            throw $this->createNotFoundException();
        }

        $nonce = bin2hex(random_bytes(32));
        $request->getSession()->set(EntraAuthenticator::NONCE_SESSION_KEY, $nonce);
        $request->getSession()->set(EntraAuthenticator::LOCALE_SESSION_KEY, $request->getLocale());

        return $clientRegistry->getClient('entra')->redirect([], ['nonce' => $nonce]);
    }

    #[Route('/sso/callback', name: 'sso_callback', methods: ['GET'])]
    public function ssoCallback(): never
    {
        throw new \LogicException('De SSO-callback wordt door Symfony Security afgehandeld.');
    }

    #[Route('/{_locale<nl|en>}/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Logout wordt door Symfony Security afgehandeld.');
    }
}
