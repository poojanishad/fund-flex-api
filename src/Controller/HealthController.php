<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client;

class HealthController
{
    #[Route('/health', methods: ['GET'])]
    public function check(EntityManagerInterface $em, Client $redis): JsonResponse
    {
        try {
            $em->getConnection()->executeQuery('SELECT 1');
            $db = 'UP';
        } catch (\Throwable $e) {
            $db = 'DOWN';
        }

        try {
            $redis->ping();
            $cache = 'UP';
        } catch (\Throwable $e) {
            $cache = 'DOWN';
        }

        return new JsonResponse([
            'status' => ($db === 'UP' && $cache === 'UP') ? 'OK' : 'DEGRADED',
            'database' => $db,
            'redis' => $cache
        ]);
    }
}