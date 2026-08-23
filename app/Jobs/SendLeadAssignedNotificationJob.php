<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendLeadAssignedNotificationJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public function __construct(
        private readonly string $leadId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationDriverFactory $factory): void
    {
        $lead = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->find($this->leadId);

        if (! $lead || $lead->assigned_user_id === null) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $driver = $factory->resolve($setting !== null ? $setting->notification_driver : 'telegram');

        $driver->sendLeadAssignedAlert($lead);
    }
}
