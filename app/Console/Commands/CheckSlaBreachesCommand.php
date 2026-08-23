<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Jobs\EscalateLeadSlaJob;
use App\Models\Lead;
use Illuminate\Console\Command;

class CheckSlaBreachesCommand extends Command
{
    protected $signature = 'sla:check-breaches';

    protected $description = 'Dispatch escalation jobs for leads whose SLA deadline has passed';

    public function handle(): int
    {
        $breachedCount = 0;

        Lead::withoutGlobalScopes()
            ->where('sla_status', SlaStatus::Active)
            ->whereNull('first_action_at')
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Claimed->value])
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->orderBy('id')
            ->each(function (Lead $lead) use (&$breachedCount): void {
                $lead->update(['sla_status' => SlaStatus::Breached]);
                EscalateLeadSlaJob::dispatch($lead->id);
                $breachedCount++;
            });

        $this->info("Dispatched {$breachedCount} SLA breach escalation job(s).");

        return self::SUCCESS;
    }
}
