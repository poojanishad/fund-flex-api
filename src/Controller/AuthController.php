<?php

namespace App\Controller;

use Predis\Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class AuthController
{
    public function __construct(
        private readonly Client $redis,
        private readonly string $apiUserEmail,
        private readonly string $apiPasswordHash
    ) {}

    /**
     * POST /api/login
     *
     * Body: {"email": "admin@example.com", "password": "secret"}
     * Returns: {"token": "<hex>", "expires_in": 3600}
     */
    #[Route('/api/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'], $data['password'])) {
            return new JsonResponse(['error' => 'email and password are required'], 400);
        }

        if (
            $data['email'] !== $this->apiUserEmail
            || !password_verify($data['password'], $this->apiPasswordHash)
        ) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $this->redis->setex("auth_token:{$token}", 3600, $this->apiUserEmail);

        return new JsonResponse([
            'token'      => $token,
            'expires_in' => 3600,
        ]);
    }
}
