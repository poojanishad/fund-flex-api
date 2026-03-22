<?php

namespace App\Service;

use App\DTO\TransferRequest;
use App\Entity\Account;
use App\Entity\IdempotencyKey;
use App\Entity\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TransferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly Client $redis,
        private readonly RequestStack $requestStack,
        private readonly AuditService $auditService
    ) {}

    public function transfer(TransferRequest $dto): array
    {
        $this->checkRateLimit();

        $redisKey = 'txn:' . $dto->idempotencyKey;

        // Fast-path: Redis idempotency check (hot cache)
        if ($this->redis->exists($redisKey)) {
            return json_decode($this->redis->get($redisKey), true);
        }

        // Durable idempotency: survive Redis restarts
        $existing = $this->em->getRepository(IdempotencyKey::class)
            ->findOneBy(['key' => $dto->idempotencyKey]);

        if ($existing) {
            return $existing->getResponse();
        }

        // Guard against duplicate business references (different from idempotency key)
        $existingTxn = $this->em->getRepository(Transaction::class)
            ->findOneBy(['referenceId' => $dto->referenceId]);

        if ($existingTxn) {
            throw new BadRequestHttpException('Duplicate referenceId');
        }

        $conn = $this->em->getConnection();

        try {
            $conn->beginTransaction();

            // Acquire row-level locks on both accounts in a consistent order
            // to prevent deadlocks under concurrent load.
            $ids = [$dto->fromAccount, $dto->toAccount];
            sort($ids);
            $conn->executeQuery(
                'SELECT id FROM account WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                $ids
            );

            $from = $this->em->find(Account::class, $dto->fromAccount);
            $to   = $this->em->find(Account::class, $dto->toAccount);

            if (!$from || !$to) {
                throw new NotFoundHttpException('Account not found');
            }

            if ($dto->fromAccount === $dto->toAccount) {
                throw new BadRequestHttpException('Cannot transfer to the same account');
            }

            $fromBefore = $from->getBalance();
            $toBefore   = $to->getBalance();

            if (bccomp($fromBefore, (string) $dto->amount, 2) < 0) {
                throw new BadRequestHttpException('Insufficient balance');
            }

            $fromAfter = bcsub($fromBefore, (string) $dto->amount, 2);
            $toAfter   = bcadd($toBefore, (string) $dto->amount, 2);

            $from->setBalance($fromAfter);
            $to->setBalance($toAfter);

            $txn = new Transaction();
            $txn->setFromAccount($from);
            $txn->setToAccount($to);
            $txn->setFromBeforeBalance($fromBefore);
            $txn->setFromAfterBalance($fromAfter);
            $txn->setToBeforeBalance($toBefore);
            $txn->setToAfterBalance($toAfter);
            $txn->setAmount((string) $dto->amount);
            $txn->setStatus('SUCCESS');
            $txn->setReferenceId($dto->referenceId);

            $this->em->persist($txn);

            $response = [
                'success' => true,
                'data'    => [
                    'status'      => 'SUCCESS',
                    'referenceId' => $dto->referenceId,
                ],
            ];

            $idem = new IdempotencyKey();
            $idem->setKey($dto->idempotencyKey);
            $idem->setResponse($response);
            $idem->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($idem);
            $this->em->flush();
            $conn->commit();

            // Cache in Redis for fast idempotency lookups
            $this->redis->setex($redisKey, 3600, json_encode($response));

            $this->auditService->log('TRANSFER_SUCCESS', [
                'referenceId'    => $dto->referenceId,
                'amount'         => $dto->amount,
                'fromAccount'    => $dto->fromAccount,
                'toAccount'      => $dto->toAccount,
                'idempotencyKey' => $dto->idempotencyKey,
            ]);

            return $response;

        } catch (\Throwable $e) {
            $conn->rollBack();

            $this->auditService->log('TRANSFER_FAILED', [
                'referenceId' => $dto->referenceId,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Simple Redis sliding counter: max 10 requests per IP per 60 seconds.
     */
    private function checkRateLimit(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $ip      = $request?->getClientIp() ?? 'unknown';

        $key   = 'rate_limit:' . $ip;
        $count = $this->redis->incr($key);

        if ($count === 1) {
            $this->redis->expire($key, 60);
        }

        if ($count > 10) {
            throw new TooManyRequestsHttpException(60, 'Rate limit exceeded: max 10 requests per minute');
        }
    }
}
