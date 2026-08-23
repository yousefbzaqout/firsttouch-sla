<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiRoutingMode;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantPlanType;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantSetting> */
class TenantSettingFactory extends Factory
{
    protected $model = TenantSetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sla_timeout_minutes' => 5,
            'ai_routing_mode' => AiRoutingMode::HumanOnly,
            'ai_confidence_threshold' => 90.00,
            'ai_selected_model' => (string) config('services.openrouter.model', 'openrouter/free'),
            'credits_balance' => 10,
            'plan_type' => TenantPlanType::Free,
            'subscription_status' => SubscriptionStatus::Active,
            'notification_driver' => 'telegram',
            'timezone' => 'UTC',
            'meta_webhook_secret' => null,
            'tiktok_webhook_secret' => null,
            'google_webhook_secret' => null,
            'snapchat_webhook_secret' => null,
            'universal_webhook_secret' => null,
            'website_api_key' => null,
        ];
    }
}
