<?php

namespace App\EventListener;

use Predis\Client;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Validates Bearer token on every /api/* request except /api/login.
 * Tokens are issued by AuthController and stored in Redis with a 1-hour TTL.
 */
class ApiAuthListener implements EventSubscriberInterface
{
    public function __construct(private readonly Client $redis) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        // Only guard /api/* — skip the login endpoint itself
        if (!str_starts_with($path, '/api/') || $path === '/api/login') {
            return;
        }

        $auth = $event->getRequest()->headers->get('Authorization', '');

        if (!str_starts_with($auth, 'Bearer ')) {
            $event->setResponse(new JsonResponse(['error' => 'Authorization token required'], 401));
            return;
        }

        $token = substr($auth, 7);

        if (!$this->redis->exists("auth_token:{$token}")) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid or expired token'], 401));
        }
    }
}
