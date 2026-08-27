<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class ReadOnlyRequestSubscriber implements EventSubscriberInterface
{
    private const MUTATING_GET_ROUTES = [
        'reports_new',
        'report_series_new_project_object',
        'report_series_new_object',
    ];

    private const ALLOWED_MUTATING_ROUTES = [
        'profile_edit',
        'feedback',
    ];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->security->isGranted(User::ROLE_READ_ONLY)) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if (in_array($route, self::ALLOWED_MUTATING_ROUTES, true)) {
            return;
        }

        if ($request->isMethodSafe() && !in_array($route, self::MUTATING_GET_ROUTES, true)) {
            return;
        }

        throw new AccessDeniedHttpException('Read-only users cannot make changes.');
    }
}
