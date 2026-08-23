<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\LeadSource;
use App\Models\TenantSetting;

class WebhookSecretResolver
{
    public function resolve(LeadSource $source, string $tenantId): string
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $tenantSecret = match ($source) {
            LeadSource::Meta => $setting?->meta_webhook_secret,
            LeadSource::TikTok => $setting?->tiktok_webhook_secret,
            LeadSource::Google => $setting?->google_webhook_secret,
            LeadSource::Snapchat => $setting?->snapchat_webhook_secret,
            LeadSource::Universal => $setting?->universal_webhook_secret,
            LeadSource::Website => $setting?->website_api_key,
            default => null,
        };

        if (is_string($tenantSecret) && $tenantSecret !== '') {
            return $tenantSecret;
        }

        $configKey = match ($source) {
            LeadSource::Meta => 'services.webhooks.meta_secret',
            LeadSource::TikTok => 'services.webhooks.tiktok_secret',
            LeadSource::Google => 'services.webhooks.google_secret',
            LeadSource::Snapchat => 'services.webhooks.snapchat_secret',
            LeadSource::Universal => 'services.webhooks.universal_secret',
            LeadSource::Website => 'services.webhooks.website_secret',
            default => null,
        };

        if ($configKey === null) {
            return '';
        }

        return (string) config($configKey, '');
    }
}
