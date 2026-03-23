<?php

namespace App\EventListener;

use Predis\Client;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

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

        if (!str_starts_with($path, '/api/') || $path === '/api/login') {
            return;
        }

        $auth = $event->getRequest()->headers->get('Authorization', '');

        if (!str_starts_with($auth, 'Bearer ')) {
            $event->setResponse(new JsonResponse(['error' => 'Authorization token required'], 401));
            return;
        }

        $token     = substr($auth, 7);
        $redisKey  = "auth_token:{$token}";
        $accountId = $this->redis->get($redisKey);

        if (!$accountId) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid or expired token'], 401));
            return;
        }

        $event->getRequest()->attributes->set('auth.account_id', (int) $accountId);
    }
}
