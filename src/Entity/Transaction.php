<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transactions')]
#[ORM\HasLifecycleCallbacks]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sentTransactions')]
    #[ORM\JoinColumn(nullable: false)]
    private Account $fromAccount;

    #[ORM\ManyToOne(inversedBy: 'receivedTransactions')]
    #[ORM\JoinColumn(nullable: false)]
    private Account $toAccount;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $fromBeforeBalance;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $fromAfterBalance;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $toBeforeBalance;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $toAfterBalance;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(length: 100, unique: true)]
    private string $referenceId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\PrePersist]
    public function onCreate(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getFromAccount(): Account { return $this->fromAccount; }
    public function setFromAccount(Account $fromAccount): self { $this->fromAccount = $fromAccount; return $this; }

    public function getToAccount(): Account { return $this->toAccount; }
    public function setToAccount(Account $toAccount): self { $this->toAccount = $toAccount; return $this; }

    public function setFromBeforeBalance(string $b): self { $this->fromBeforeBalance = $b; return $this; }
    public function getFromBeforeBalance(): string { return $this->fromBeforeBalance; }

    public function setFromAfterBalance(string $b): self { $this->fromAfterBalance = $b; return $this; }
    public function getFromAfterBalance(): string { return $this->fromAfterBalance; }

    public function setToBeforeBalance(string $b): self { $this->toBeforeBalance = $b; return $this; }
    public function getToBeforeBalance(): string { return $this->toBeforeBalance; }

    public function setToAfterBalance(string $b): self { $this->toAfterBalance = $b; return $this; }
    public function getToAfterBalance(): string { return $this->toAfterBalance; }

    public function setAmount(string $amount): self { $this->amount = $amount; return $this; }
    public function getAmount(): string { return $this->amount; }

    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getStatus(): string { return $this->status; }

    public function setReferenceId(string $ref): self { $this->referenceId = $ref; return $this; }
    public function getReferenceId(): string { return $this->referenceId; }

    public function setCreatedAt(\DateTimeImmutable $dt): self { $this->createdAt = $dt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
