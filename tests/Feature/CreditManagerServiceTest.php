<?php

declare(strict_types=1);

use App\Enums\AiRoutingMode;
use App\Jobs\DailyFreeCreditGrantJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Ai\CreditManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deducts credit from tenant', function (): void {
    config(['services.telegram.low_credits_threshold' => 1]);

    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'notification_driver' => 'log',
    ]);

    $service = app(CreditManagerService::class);
    $service->deductCredit($tenant);

    expect($setting->fresh()->credits_balance)->toBe(4);
});

it('switches to human_only when credits reach zero', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 1,
        'ai_routing_mode' => AiRoutingMode::AiFirst,
        'notification_driver' => 'log',
    ]);

    $service = app(CreditManagerService::class);
    $service->deductCredit($tenant);

    $setting->refresh();

    expect($setting->credits_balance)->toBe(0)
        ->and($setting->ai_routing_mode)->toBe(AiRoutingMode::HumanOnly);
});

it('reports has no available credits when balance is zero', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 0,
    ]);

    $service = app(CreditManagerService::class);

    expect($service->hasAvailableCredits($tenant))->toBeFalse();
});

it('grants 1 free credit to zero-balance active tenants via daily job', function (): void {
    $activeTenant = Tenant::factory()->create(['is_active' => true]);
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $activeTenant->id,
        'credits_balance' => 0,
    ]);

    $inactiveTenant = Tenant::factory()->create(['is_active' => false]);
    $inactiveSetting = TenantSetting::factory()->create([
        'tenant_id' => $inactiveTenant->id,
        'credits_balance' => 0,
    ]);

    $richTenant = Tenant::factory()->create(['is_active' => true]);
    $richSetting = TenantSetting::factory()->create([
        'tenant_id' => $richTenant->id,
        'credits_balance' => 5,
    ]);

    (new DailyFreeCreditGrantJob)->handle();

    expect($setting->fresh()->credits_balance)->toBe(1)
        ->and($inactiveSetting->fresh()->credits_balance)->toBe(0)
        ->and($richSetting->fresh()->credits_balance)->toBe(5);
});
