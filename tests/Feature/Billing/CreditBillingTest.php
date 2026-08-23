<?php

declare(strict_types=1);

use App\DTOs\LeadData;
use App\Enums\AiRoutingMode;
use App\Enums\LeadSource;
use App\Enums\PaymentLedgerType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessAiResponseJob;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Billing\CreditManagementService;
use App\Services\Billing\StripeBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('deducts and adds credits with ledger PaymentTransaction rows', function (): void {
    config(['services.telegram.low_credits_threshold' => 1]);

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'ai_routing_mode' => AiRoutingMode::AiAssisted,
        'notification_driver' => 'log',
    ]);

    $service = app(CreditManagementService::class);

    expect($service->hasEnoughCredits($tenant, 2))->toBeTrue();

    $deducted = $service->deductCredits($tenant, 2, 'AI response inference');

    expect($deducted)->toBeTrue()
        ->and($setting->fresh()->credits_balance)->toBe(3);

    $deduction = PaymentTransaction::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', PaymentLedgerType::AiDeduction)
        ->first();

    expect($deduction)->not->toBeNull()
        ->and($deduction->credits_added)->toBe(-2)
        ->and($deduction->description)->toBe('AI response inference')
        ->and($deduction->status)->toBe(PaymentStatus::Completed);

    $topup = $service->addCredits($tenant, 10, 'Manual credit grant');

    expect($topup)->toBeInstanceOf(PaymentTransaction::class)
        ->and($topup->type)->toBe(PaymentLedgerType::Topup)
        ->and($topup->credits_added)->toBe(10)
        ->and($topup->description)->toBe('Manual credit grant')
        ->and($setting->fresh()->credits_balance)->toBe(13);
});

it('freezes AI processing during ingestion when tenant credit balance is zero', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 0,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
        'notification_driver' => 'log',
        'timezone' => 'UTC',
    ]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $leadData = new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: 'credit-freeze-lead-001',
        name: 'Zero Credit Lead',
        phone: '+15550001111',
        email: 'zero@example.com',
        campaignId: 'camp-1',
        formId: 'form-1',
        rawPayload: ['notice' => 'insufficient_credits_test'],
    );

    $lead = app(LeadProcessingPipeline::class)->process($leadData);

    Queue::assertNotPushed(ProcessAiResponseJob::class);

    expect($lead)->not->toBeNull()
        ->and(data_get($lead?->meta_data, 'ai_skipped_reason'))->toBe('insufficient_credits')
        ->and(data_get($lead?->meta_data, 'ai_skipped_notice'))->toContain('Low Credit Balance')
        ->and(app(CreditManagementService::class)->hasEnoughCredits($tenant))->toBeFalse();
});

it('adds credits to the correct tenant from a successful Stripe payment webhook', function (): void {
    config([
        'services.stripe.secret' => 'sk_test_billing',
        'services.stripe.webhook_secret' => 'whsec_test_billing',
    ]);

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 0,
        'ai_routing_mode' => AiRoutingMode::HumanOnly,
        'notification_driver' => 'log',
    ]);

    $otherTenant = Tenant::factory()->create();
    $otherSetting = TenantSetting::factory()->create([
        'tenant_id' => $otherTenant->id,
        'credits_balance' => 7,
        'notification_driver' => 'log',
    ]);

    Http::fake([
        'api.stripe.com/v1/checkout/sessions' => Http::response([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
        ], 200),
    ]);

    $billing = app(StripeBillingService::class);

    $checkoutUrl = $billing->createCheckoutSession(
        $tenant,
        1000,
        'https://example.test/success',
        'https://example.test/cancel',
    );

    expect($checkoutUrl)->toBe('https://checkout.stripe.com/c/pay/cs_test_123');

    Http::assertSent(function ($request): bool {
        $body = (string) $request->body();

        return str_contains($request->url(), 'api.stripe.com/v1/checkout/sessions')
            && str_contains($body, 'metadata%5Bcredits%5D=1000')
            && str_contains($body, 'metadata%5Btenant_id%5D=');
    });

    $session = [
        'id' => 'cs_test_123',
        'payment_intent' => 'pi_test_credits_001',
        'amount_total' => 2000,
        'currency' => 'usd',
        'metadata' => [
            'tenant_id' => $tenant->id,
            'credits' => '1000',
            'ledger_type' => PaymentLedgerType::Topup->value,
        ],
    ];

    $event = [
        'type' => 'checkout.session.completed',
        'data' => ['object' => $session],
    ];

    $rawBody = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, 'whsec_test_billing');

    $billing->handlePaymentSuccessWebhook([
        ...$event,
        '_raw_body' => $rawBody,
        '_signature' => "t={$timestamp},v1={$signature}",
    ]);

    expect($setting->fresh()->credits_balance)->toBe(1000)
        ->and($setting->fresh()->ai_routing_mode)->toBe(AiRoutingMode::AiFirst)
        ->and($otherSetting->fresh()->credits_balance)->toBe(7);

    $transaction = PaymentTransaction::withoutGlobalScopes()
        ->where('transaction_id', 'pi_test_credits_001')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->tenant_id)->toBe($tenant->id)
        ->and($transaction->type)->toBe(PaymentLedgerType::Topup)
        ->and($transaction->credits_added)->toBe(1000)
        ->and($transaction->driver)->toBe('stripe')
        ->and((float) $transaction->amount)->toBe(20.0);
});

it('rejects Stripe webhooks with an invalid signature', function (): void {
    config(['services.stripe.webhook_secret' => 'whsec_test_billing']);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 1,
        'notification_driver' => 'log',
    ]);

    $billing = app(StripeBillingService::class);

    expect(fn () => $billing->handlePaymentSuccessWebhook([
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_bad',
                'payment_intent' => 'pi_bad',
                'amount_total' => 2000,
                'currency' => 'usd',
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'credits' => '1000',
                ],
            ],
        ],
        '_raw_body' => '{}',
        '_signature' => 't=1,v1=invalid',
    ]))->toThrow(InvalidArgumentException::class);
});
