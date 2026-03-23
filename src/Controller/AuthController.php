<?php

namespace App\Controller;

use App\Repository\AccountRepository;
use Predis\Client;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class AuthController
{
    public function __construct(
        private readonly Client $redis,
        private readonly AccountRepository $accounts,
    ) {}

    #[Route('/api/login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['login'], $data['password'])) {
            return new JsonResponse(['error' => 'login (username or email) and password are required'], 400);
        }

        $account = $this->accounts->findByUsernameOrEmail($data['login']);

        if ($account === null || !password_verify($data['password'], $account->getPassword())) {
            return new JsonResponse(['error' => 'Invalid credentials'], 401);
        }

        $token = bin2hex(random_bytes(32));

        $this->redis->setex("auth_token:{$token}", 3600, (string) $account->getId());

        return new JsonResponse([
            'token'      => $token,
            'expires_in' => 3600,
            'account_id' => $account->getId(),
        ]);
    }
}