<?php

declare(strict_types=1);

use App\Enums\AiRoutingMode;
use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Payments\Drivers\MockPaymentDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates checkout session successfully with mock driver', function (): void {
    $tenant = Tenant::factory()->create();
    $driver = new MockPaymentDriver;

    $sessionId = $driver->createCheckoutSession($tenant, 10, 9.99);

    expect($sessionId)->toContain('/payments/mock/checkout')
        ->and($sessionId)->toContain('signature=');
});

it('shows mock stripe checkout without crediting until pay is confirmed', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
    ]);

    $checkoutUrl = (new MockPaymentDriver)->createCheckoutSession($tenant, 100, 9.99);

    $this->get($checkoutUrl)
        ->assertOk()
        ->assertSee('Stripe Mock Gateway')
        ->assertSee('Pay $9.99')
        ->assertSee('100 AI Credits');

    expect($setting->fresh()->credits_balance)->toBe(5);
});

it('credits the tenant only after mock payment confirmation', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
    ]);

    $checkoutUrl = (new MockPaymentDriver)->createCheckoutSession($tenant, 100, 9.99);
    $page = $this->get($checkoutUrl);
    $page->assertOk();

    $payUrl = null;
    if (preg_match('/action="([^"]+payments\\/mock\\/checkout\\/pay[^"]*)"/', $page->getContent(), $matches) === 1) {
        $payUrl = html_entity_decode($matches[1], ENT_QUOTES);
    }

    expect($payUrl)->not->toBeNull();

    $this->post($payUrl)
        ->assertRedirect('/admin/credit-top-up');

    expect($setting->fresh()->credits_balance)->toBe(105);
});

it('does not credit the tenant when mock checkout is cancelled', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
    ]);

    $checkoutUrl = (new MockPaymentDriver)->createCheckoutSession($tenant, 100, 9.99);
    $page = $this->get($checkoutUrl)->assertOk();

    $cancelUrl = null;
    if (preg_match('/href="([^"]+payments\\/mock\\/checkout\\/cancel[^"]*)"/', $page->getContent(), $matches) === 1) {
        $cancelUrl = html_entity_decode($matches[1], ENT_QUOTES);
    }

    expect($cancelUrl)->not->toBeNull();

    $this->get($cancelUrl)
        ->assertRedirect('/admin/credit-top-up');

    expect($setting->fresh()->credits_balance)->toBe(5);
});

it('processes successful webhook and increments credits_balance', function (): void {
    $this->app['env'] = 'local';

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
    ]);

    config(['payments.default_driver' => 'mock']);

    $response = $this->postJson('/api/v1/payments/webhook/mock', [
        'tenant_id' => $tenant->id,
        'transaction_id' => 'txn-mock-001',
        'credits' => 20,
        'amount' => 19.99,
        'currency' => 'USD',
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'processed']);

    expect($setting->fresh()->credits_balance)->toBe(25);

    $transaction = PaymentTransaction::withoutGlobalScopes()
        ->where('transaction_id', 'txn-mock-001')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->status)->toBe(PaymentStatus::Completed)
        ->and($transaction->credits_added)->toBe(20);
});

it('restores ai_routing_mode from human_only to ai_first after credit top-up', function (): void {
    $this->app['env'] = 'local';

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 0,
        'ai_routing_mode' => AiRoutingMode::HumanOnly,
    ]);

    config(['payments.default_driver' => 'mock']);

    $this->postJson('/api/v1/payments/webhook/mock', [
        'tenant_id' => $tenant->id,
        'transaction_id' => 'txn-restore-001',
        'credits' => 10,
        'amount' => 9.99,
        'currency' => 'USD',
    ]);

    $setting->refresh();

    expect($setting->credits_balance)->toBe(10)
        ->and($setting->ai_routing_mode)->toBe(AiRoutingMode::AiFirst);
});
