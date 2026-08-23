<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\DTOs\PaymentResponseDTO;
use App\Models\Tenant;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MockPaymentDriver implements PaymentGatewayInterface
{
    public function createCheckoutSession(Tenant $tenant, int $creditsCount, float $amount): string
    {
        return URL::temporarySignedRoute(
            'payments.mock.checkout',
            now()->addMinutes(30),
            [
                'tenant_id' => $tenant->id,
                'credits' => $creditsCount,
                'amount' => $amount,
                'session' => (string) Str::uuid(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, string $signature): PaymentResponseDTO
    {
        return new PaymentResponseDTO(
            success: true,
            transactionId: (string) ($payload['transaction_id'] ?? Str::uuid()->toString()),
            creditsPurchased: (int) ($payload['credits'] ?? 10),
            amountPaid: (float) ($payload['amount'] ?? 9.99),
            currency: (string) ($payload['currency'] ?? 'USD'),
        );
    }
}
