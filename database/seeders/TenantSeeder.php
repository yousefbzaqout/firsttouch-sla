<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiRoutingMode;
use App\Enums\OffHoursAction;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['domain' => 'demo.firsttouch.local'],
            [
                'name' => 'Demo Tenant',
                'is_active' => true,
            ],
        );

        TenantSetting::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'sla_timeout_minutes' => 15,
                'ai_routing_mode' => AiRoutingMode::AiFirst,
                'ai_confidence_threshold' => 85.00,
                'credits_balance' => 50,
                'notification_driver' => 'n8n',
                'timezone' => 'Asia/Riyadh',
            ],
        );

        for ($day = 0; $day <= 6; $day++) {
            TenantWorkingHour::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'day_of_week' => $day,
                ],
                [
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'off_hours_action' => OffHoursAction::FreezeSla,
                ],
            );
        }

        User::query()->updateOrCreate(
            ['email' => 'admin@demo.firsttouch.local'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );
    }
}
