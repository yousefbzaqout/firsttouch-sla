<?php

declare(strict_types=1);

namespace App\Services\Payments\Drivers;

use App\DTOs\PaymentResponseDTO;
use App\Models\Tenant;
use App\Services\Payments\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Http;

class StripePaymentDriver implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
    ) {}

    public function createCheckoutSession(Tenant $tenant, int $creditsCount, float $amount): string
    {
        $response = Http::withToken($this->secretKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => config('app.url').'/payments/success',
                'cancel_url' => config('app.url').'/payments/cancel',
                'line_items[0][price_data][currency]' => 'usd',
                'line_items[0][price_data][unit_amount]' => (int) ($amount * 100),
                'line_items[0][price_data][product_data][name]' => "{$creditsCount} AI Credits",
                'line_items[0][quantity]' => 1,
                'metadata[tenant_id]' => $tenant->id,
                'metadata[credits]' => $creditsCount,
            ]);

        return (string) $response->json('url', $response->json('id', ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload, string $signature): PaymentResponseDTO
    {
        $payloadString = json_encode($payload) ?: '';
        $expected = hash_hmac('sha256', $payloadString, $this->webhookSecret);

        if (! hash_equals($expected, $signature)) {
            return new PaymentResponseDTO(
                success: false,
                transactionId: '',
                creditsPurchased: 0,
                amountPaid: 0.0,
                currency: 'USD',
                errorMessage: 'Invalid webhook signature',
            );
        }

        $session = $payload['data']['object'] ?? [];

        return new PaymentResponseDTO(
            success: true,
            transactionId: (string) ($session['payment_intent'] ?? $session['id'] ?? ''),
            creditsPurchased: (int) ($session['metadata']['credits'] ?? 0),
            amountPaid: ((float) ($session['amount_total'] ?? 0)) / 100.0,
            currency: (string) ($session['currency'] ?? 'usd'),
        );
    }
}
