<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RegisterTelegramWebhookJob;
use App\Models\TenantSetting;

class TenantSettingObserver
{
    public function created(TenantSetting $setting): void
    {
        $this->dispatchWhenTokenPresent($setting);
    }

    public function updated(TenantSetting $setting): void
    {
        if (! $setting->wasChanged('telegram_bot_token')) {
            return;
        }

        $this->dispatchWhenTokenPresent($setting);
    }

    private function dispatchWhenTokenPresent(TenantSetting $setting): void
    {
        $token = is_string($setting->telegram_bot_token)
            ? trim($setting->telegram_bot_token)
            : '';

        if ($token === '') {
            return;
        }

        RegisterTelegramWebhookJob::dispatch($setting->tenant_id);
    }
}
