<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class TransferRequest
{
    #[Assert\NotBlank]
    #[Assert\Type("integer")]
    public int $fromAccount;

    #[Assert\NotBlank]
    #[Assert\Type("integer")]
    public int $toAccount;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public float $amount;

    #[Assert\NotBlank]
    public string $referenceId;

    #[Assert\NotBlank]
    public string $idempotencyKey;
}