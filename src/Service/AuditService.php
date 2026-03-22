<?php

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AuditService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    public function log(string $eventType, array $payload): void
    {
        try {
            $audit = new AuditLog();

            // ✅ event type (TRANSFER_SUCCESS, FAILED etc)
            $audit->setEventType($eventType);

            // ✅ full payload store (debug + audit)
            $audit->setPayload($payload);

            // ✅ timestamp
            $audit->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($audit);
            $this->em->flush(); // ✅ IMPORTANT (instant insert)

        } catch (\Throwable $e) {
            // ❌ audit should NEVER break main flow
            $this->logger->error('Audit failed', [
                'event' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
}
