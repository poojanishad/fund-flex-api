<?php

namespace App\Controller;

use App\Service\TransferService;
use App\DTO\TransferRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class TransferController
{
    #[Route('/api/transfer', methods: ['POST'])]
    public function transfer(
        Request $request,
        TransferService $service,
        ValidatorInterface $validator
    ): JsonResponse {

        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        if (!isset($data['fromAccount'], $data['toAccount'], $data['amount'], $data['referenceId'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $dto = new TransferRequest();
        $dto->fromAccount = (int)$data['fromAccount'];
        $dto->toAccount   = (int)$data['toAccount'];
        $dto->amount      = (float)$data['amount'];
        $dto->referenceId = $data['referenceId'];

        $key = $request->headers->get('Idempotency-Key');
        if (!$key) {
            return new JsonResponse(['error' => 'Idempotency-Key missing'], 400);
        }

        $dto->idempotencyKey = $key;

        $errors = $validator->validate($dto);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => (string)$errors], 400);
        }

        try {
            return new JsonResponse($service->transfer($dto));

        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Internal server error'], 500);
        }
    }
}