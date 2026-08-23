<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DailyFreeCreditGrantJob implements ShouldBeUnique, ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'daily-free-credit-grant:'.now()->toDateString();
    }

    public function handle(): void
    {
        TenantSetting::withoutGlobalScopes()
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->where('credits_balance', 0)
            ->increment('credits_balance', 1);
    }
}
