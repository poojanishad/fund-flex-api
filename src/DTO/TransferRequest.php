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
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'Amount must be a positive number with up to 2 decimal places'
    )]
    public string $amount;

    #[Assert\NotBlank]
    public string $referenceId;

    #[Assert\NotBlank]
    public string $idempotencyKey;
}