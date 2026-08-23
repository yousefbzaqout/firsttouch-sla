<?php

declare(strict_types=1);

namespace App\Pipelines\Pipes;

use App\DTOs\LeadData;
use App\Jobs\ProcessAiResponseJob;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Services\Billing\CreditManagementService;
use Carbon\Carbon;
use Closure;

class DispatchAiResponsePipe
{
    public function __construct(
        private readonly CreditManagementService $creditManagement,
    ) {}

    /**
     * @param  array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null, sla_deadline?: Carbon|null}  $passable
     * @return array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null, sla_deadline?: Carbon|null}
     */
    public function handle(array $passable, Closure $next): array
    {
        $lead = $passable['lead'] ?? null;

        if ($lead instanceof Lead) {
            $this->maybeDispatch($lead);
        }

        return $next($passable);
    }

    private function maybeDispatch(Lead $lead): void
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        if ($setting === null) {
            $this->dispatchAssignmentNotification($lead);

            return;
        }

        $mode = $setting->ai_routing_mode;
        $tenant = $lead->tenant;

        if ($mode->runsAi() && $tenant !== null) {
            if ($this->creditManagement->hasEnoughCredits($tenant, 1)) {
                ProcessAiResponseJob::dispatch($lead->id);

                return;
            }

            $meta = $lead->meta_data ?? [];
            $meta['ai_skipped_reason'] = 'insufficient_credits';
            $meta['ai_skipped_notice'] = 'Low Credit Balance — AI processing frozen until credits are topped up.';
            $lead->update(['meta_data' => $meta]);
        }

        // Human First / Human Only (or AI modes without credits): notify if already assigned.
        $this->dispatchAssignmentNotification($lead);
    }

    private function dispatchAssignmentNotification(Lead $lead): void
    {
        if ($lead->assigned_user_id === null) {
            return;
        }

        SendLeadAssignedNotificationJob::dispatch($lead->id);
    }
}
