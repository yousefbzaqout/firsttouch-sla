<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PaymentLedgerType;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class StripeBillingService
{
    public function __construct(
        private readonly CreditManagementService $creditManagement,
    ) {}

    public function createCheckoutSession(
        Tenant $tenant,
        int $creditsToBuy,
        string $successUrl,
        string $cancelUrl,
    ): string {
        if ($creditsToBuy < 1) {
            throw new InvalidArgumentException('creditsToBuy must be at least 1.');
        }

        $amount = $this->amountForCredits($creditsToBuy);
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new RuntimeException('Stripe secret key is not configured.');
        }

        $response = Http::withToken($secret)
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items[0][price_data][currency]' => 'usd',
                'line_items[0][price_data][unit_amount]' => (int) round($amount * 100),
                'line_items[0][price_data][product_data][name]' => "{$creditsToBuy} AI Credits",
                'line_items[0][quantity]' => 1,
                'metadata[tenant_id]' => $tenant->id,
                'metadata[credits]' => (string) $creditsToBuy,
                'metadata[ledger_type]' => PaymentLedgerType::Topup->value,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to create Stripe Checkout session: '.$response->body());
        }

        $url = (string) $response->json('url', '');

        if ($url === '') {
            throw new RuntimeException('Stripe Checkout session did not return a redirect URL.');
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $stripePayload
     */
    public function handlePaymentSuccessWebhook(array $stripePayload): void
    {
        $signature = (string) ($stripePayload['_signature'] ?? request()->header('Stripe-Signature', ''));
        $rawBody = (string) ($stripePayload['_raw_body'] ?? json_encode($stripePayload) ?: '');

        if (! $this->verifySignature($rawBody, $signature)) {
            throw new InvalidArgumentException('Invalid Stripe webhook signature.');
        }

        $eventType = (string) ($stripePayload['type'] ?? '');

        if ($eventType !== '' && $eventType !== 'checkout.session.completed') {
            return;
        }

        /** @var array<string, mixed> $session */
        $session = is_array($stripePayload['data']['object'] ?? null)
            ? $stripePayload['data']['object']
            : $stripePayload;

        /** @var array<string, mixed> $metadata */
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

        $tenantId = (string) ($metadata['tenant_id'] ?? $stripePayload['tenant_id'] ?? '');

        if ($tenantId === '') {
            throw new InvalidArgumentException('Stripe webhook missing tenant_id metadata.');
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new InvalidArgumentException("Unknown tenant_id in Stripe webhook: {$tenantId}");
        }

        $credits = (int) ($metadata['credits'] ?? 0);

        if ($credits < 1) {
            throw new InvalidArgumentException('Stripe webhook missing credits metadata.');
        }

        $ledgerType = PaymentLedgerType::tryFrom((string) ($metadata['ledger_type'] ?? PaymentLedgerType::Topup->value))
            ?? PaymentLedgerType::Topup;

        $transactionId = (string) ($session['payment_intent'] ?? $session['id'] ?? '');

        if ($transactionId === '') {
            throw new InvalidArgumentException('Stripe webhook missing payment identifier.');
        }

        $amountPaid = ((float) ($session['amount_total'] ?? 0)) / 100.0;
        $currency = strtoupper((string) ($session['currency'] ?? 'usd'));

        $this->creditManagement->creditLedger(
            tenant: $tenant,
            amount: $credits,
            description: $ledgerType === PaymentLedgerType::MonthlySubscription
                ? 'Monthly subscription credit grant'
                : 'Stripe Checkout credit top-up',
            type: $ledgerType,
            driver: 'stripe',
            amountPaid: $amountPaid,
            currency: $currency,
            transactionId: $transactionId,
            metadata: [
                'stripe_session_id' => (string) ($session['id'] ?? ''),
                'event_type' => $eventType,
            ],
        );
    }

    public function verifySignature(string $payload, string $signatureHeader): bool
    {
        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        // Support both Stripe-Signature (t=,v1=) and simple HMAC used by the legacy driver.
        if (! str_contains($signatureHeader, 't=') && ! str_contains($signatureHeader, 'v1=')) {
            $expected = hash_hmac('sha256', $payload, $secret);

            return hash_equals($expected, $signatureHeader);
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't') {
                $timestamp = $value;
            }

            if ($key === 'v1' && is_string($value)) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function amountForCredits(int $credits): float
    {
        /** @var list<array{credits: int, amount: float|int|string, label?: string}> $packages */
        $packages = config('payments.packages', []);

        foreach ($packages as $package) {
            if ((int) $package['credits'] === $credits) {
                return (float) $package['amount'];
            }
        }

        // Fallback linear rate: $0.02 per credit (~$20 / 1,000).
        return round($credits * 0.02, 2);
    }
}
