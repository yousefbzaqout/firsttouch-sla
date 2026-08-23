<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Jobs\DispatchOutboundWebhookJob;
use App\Models\Lead;

class LeadObserver
{
    public function updated(Lead $lead): void
    {
        if (! $lead->wasChanged(['status', 'sla_status', 'assigned_user_id'])) {
            return;
        }

        DispatchOutboundWebhookJob::dispatch($lead->id, $this->resolveEvent($lead));
    }

    private function resolveEvent(Lead $lead): string
    {
        if ($lead->wasChanged('sla_status') && $lead->sla_status === SlaStatus::Breached) {
            return 'lead.breached';
        }

        if ($lead->wasChanged('assigned_user_id') && $lead->assigned_user_id !== null) {
            $previous = $lead->getOriginal('assigned_user_id');

            if ($previous !== null && (int) $previous !== (int) $lead->assigned_user_id) {
                return 'lead.reassigned';
            }
        }

        if ($lead->wasChanged('status')) {
            return match ($lead->status) {
                LeadStatus::Contacted => 'lead.contacted',
                LeadStatus::Closed => 'lead.closed',
                LeadStatus::Lost => 'lead.lost',
                default => 'lead.updated',
            };
        }

        return 'lead.updated';
    }
}
