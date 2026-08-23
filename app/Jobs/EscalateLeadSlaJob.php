<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EscalateLeadSlaJob implements ShouldBeUnique, ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct(
        private readonly string $leadId,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'escalate-lead-sla:'.$this->leadId;
    }

    public function handle(NotificationDriverFactory $factory): void
    {
        $lead = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->find($this->leadId);

        if (! $lead) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $driver = $factory->resolve($setting !== null ? $setting->notification_driver : 'telegram');

        $assigneeName = $lead->assignedUser !== null ? $lead->assignedUser->name : 'Unassigned';

        $driver->sendEscalationAlert(
            $lead,
            "Alert: {$assigneeName} is late contacting {$lead->name} and has breached the SLA. Reassign this lead to another agent.",
        );
    }
}
