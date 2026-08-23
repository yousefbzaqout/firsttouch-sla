<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AiRoutingMode;
use App\Enums\OffHoursAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantPlanType;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final class TenantRegistrationService
{
    /**
     * @param  array{
     *     company_name: string,
     *     name: string,
     *     email: string,
     *     password: string
     * }  $data
     */
    public function register(#[SensitiveParameter] array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $tenant = Tenant::query()->create([
                'name' => $data['company_name'],
                'is_active' => true,
            ]);

            TenantSetting::query()->create([
                'tenant_id' => $tenant->id,
                'sla_timeout_minutes' => 15,
                'ai_routing_mode' => AiRoutingMode::AiFirst,
                'ai_confidence_threshold' => 85.00,
                'ai_selected_model' => (string) config('services.openrouter.model', 'openrouter/free'),
                'credits_balance' => 50,
                'plan_type' => TenantPlanType::Free,
                'subscription_status' => SubscriptionStatus::Active,
                'notification_driver' => 'n8n',
                'timezone' => 'Asia/Riyadh',
            ]);

            for ($day = 0; $day <= 6; $day++) {
                TenantWorkingHour::query()->create([
                    'tenant_id' => $tenant->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'off_hours_action' => OffHoursAction::FreezeSla,
                ]);
            }

            return User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'tenant_id' => $tenant->id,
                'role' => UserRole::Owner,
                'is_active' => true,
            ]);
        });
    }
}
