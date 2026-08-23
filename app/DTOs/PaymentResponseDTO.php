<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class PaymentResponseDTO
{
    public function __construct(
        public bool $success,
        public string $transactionId,
        public int $creditsPurchased,
        public float $amountPaid,
        public string $currency,
        public ?string $errorMessage = null,
    ) {}
}
