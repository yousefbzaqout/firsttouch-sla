<?php

declare(strict_types=1);

use App\Enums\AiRoutingMode;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use Database\Seeders\TenantSeeder;

it('seeds demo tenant with settings, working hours, and admin user', function (): void {
    $this->seed(TenantSeeder::class);

    $tenant = Tenant::query()->where('domain', 'demo.firsttouch.local')->first();

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Demo Tenant');

    $setting = TenantSetting::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->credits_balance)->toBe(50)
        ->and($setting->ai_routing_mode)->toBe(AiRoutingMode::AiFirst)
        ->and($setting->notification_driver)->toBe('n8n')
        ->and($setting->sla_timeout_minutes)->toBe(15)
        ->and((float) $setting->ai_confidence_threshold)->toBe(85.0);

    expect(
        TenantWorkingHour::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count()
    )->toBe(7);

    $admin = User::query()->where('email', 'admin@demo.firsttouch.local')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->tenant_id)->toBe($tenant->id)
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->is_active)->toBeTrue();
});
