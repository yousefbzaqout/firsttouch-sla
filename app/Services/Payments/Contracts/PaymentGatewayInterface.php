<?php

declare(strict_types=1);

namespace App\Services\Payments\Contracts;

use App\DTOs\PaymentResponseDTO;
use App\Models\Tenant;

interface PaymentGatewayInterface
{
    public function createCheckoutSession(Tenant $tenant, int $creditsCount, float $amount): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, string $signature): PaymentResponseDTO;
}
