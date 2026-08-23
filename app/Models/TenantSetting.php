<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiRoutingMode;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantPlanType;
use App\Traits\BelongsToTenant;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property AiRoutingMode $ai_routing_mode
 * @property int $credits_balance
 * @property TenantPlanType $plan_type
 * @property SubscriptionStatus $subscription_status
 * @property string $ai_selected_model
 * @property string $notification_driver
 * @property string $ai_confidence_threshold
 * @property int $sla_timeout_minutes
 * @property string|null $meta_webhook_secret
 * @property string|null $tiktok_webhook_secret
 * @property string|null $google_webhook_secret
 * @property string|null $snapchat_webhook_secret
 * @property string|null $universal_webhook_secret
 * @property string|null $website_api_key
 * @property string|null $telegram_bot_token
 * @property string|null $telegram_chat_id
 * @property string|null $outbound_webhook_url
 * @property string|null $outbound_webhook_secret
 */
class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'sla_timeout_minutes',
        'ai_routing_mode',
        'ai_confidence_threshold',
        'ai_selected_model',
        'credits_balance',
        'plan_type',
        'subscription_status',
        'notification_driver',
        'timezone',
        'meta_webhook_secret',
        'tiktok_webhook_secret',
        'google_webhook_secret',
        'snapchat_webhook_secret',
        'universal_webhook_secret',
        'website_api_key',
        'telegram_bot_token',
        'telegram_chat_id',
        'outbound_webhook_url',
        'outbound_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'ai_routing_mode' => AiRoutingMode::class,
            'plan_type' => TenantPlanType::class,
            'subscription_status' => SubscriptionStatus::class,
            'ai_confidence_threshold' => 'decimal:2',
            'sla_timeout_minutes' => 'integer',
            'credits_balance' => 'integer',
            'meta_webhook_secret' => 'encrypted',
            'tiktok_webhook_secret' => 'encrypted',
            'google_webhook_secret' => 'encrypted',
            'snapchat_webhook_secret' => 'encrypted',
            'universal_webhook_secret' => 'encrypted',
            'website_api_key' => 'encrypted',
            'telegram_bot_token' => 'encrypted',
            'outbound_webhook_secret' => 'encrypted',
        ];
    }
}
