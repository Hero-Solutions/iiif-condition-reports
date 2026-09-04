<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener(event: KernelEvents::CONTROLLER, method: 'onController', priority: 16)]
final class ActiveUserSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(bool:LOCAL_LOGIN_ENABLED)%')] private readonly bool $localLoginEnabled,
    ) {
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $message = !$user->isActive()
            ? 'login.inactive_user'
            : 'login.local_disabled';

        if ($user->isActive() && ($this->localLoginEnabled || $user->isSsoManaged())) {
            return;
        }

        $request = $event->getRequest();
        $locale = in_array($request->getLocale(), ['nl', 'en'], true) ? $request->getLocale() : 'nl';

        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $request->getSession()->getFlashBag()->add('error', $message);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login', [
            '_locale' => $locale,
        ])));
    }
}
