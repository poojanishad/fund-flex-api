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

            $audit->setEventType($eventType);

            $audit->setPayload($payload);

            $audit->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($audit);
            $this->em->flush();

        } catch (\Throwable $e) {
            $this->logger->error('Audit failed', [
                'event' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
}
