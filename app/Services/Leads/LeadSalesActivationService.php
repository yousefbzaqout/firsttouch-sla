<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\SlaStatus;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Services\Assignment\ContextAwareRoutingService;
use App\Services\Sla\BusinessHoursService;
use App\Services\Sla\SlaCalculatorService;
use App\Support\Timezones;
use Carbon\Carbon;

class LeadSalesActivationService
{
    public function __construct(
        private readonly ContextAwareRoutingService $router,
        private readonly SlaCalculatorService $slaCalculator,
        private readonly BusinessHoursService $businessHours,
    ) {}

    /**
     * Assign the lead (if needed), start the SLA clock, and optionally notify.
     */
    public function activate(Lead $lead, bool $dispatchAssignmentNotification = true): Lead
    {
        $updates = [];
        $now = Carbon::now();

        if ($lead->assigned_user_id === null) {
            $assignee = $this->router->assign($lead);
            $updates['assigned_user_id'] = $assignee?->id;
        }

        if ($lead->sla_deadline === null) {
            $setting = TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->first();

            $slaMinutes = $setting !== null ? $setting->sla_timeout_minutes : 5;

            $workingHours = TenantWorkingHour::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->get();

            $tenant = Tenant::query()->find($lead->tenant_id);

            $timezone = Timezones::resolve(
                is_string($setting?->timezone) ? $setting->timezone : null,
            );

            if ($tenant !== null && ! $this->businessHours->isWorkingHour($tenant, $now)) {
                $slaStart = $this->businessHours->getNextWorkingHourStart($tenant, $now);
                $updates['sla_deadline'] = $this->slaCalculator->calculate(
                    $slaStart->copy(),
                    $slaMinutes,
                    $workingHours,
                    $timezone,
                );
                $updates['sla_status'] = SlaStatus::Frozen;
                $updates['sla_started_at'] = $slaStart;
            } else {
                $updates['sla_deadline'] = $this->slaCalculator->calculate(
                    $now->copy(),
                    $slaMinutes,
                    $workingHours,
                    $timezone,
                );
                $updates['sla_status'] = SlaStatus::Active;
                $updates['sla_started_at'] = $now;
            }

            $updates['sla_warning_sent_at'] = null;
            $updates['sla_escalated_at'] = null;
        } elseif ($lead->sla_status === SlaStatus::Pending) {
            $tenant = Tenant::query()->find($lead->tenant_id);

            if ($tenant !== null && ! $this->businessHours->isWorkingHour($tenant, $now)) {
                $slaStart = $this->businessHours->getNextWorkingHourStart($tenant, $now);
                $updates['sla_status'] = SlaStatus::Frozen;
                $updates['sla_started_at'] = $slaStart;
            } else {
                $updates['sla_status'] = SlaStatus::Active;
                $updates['sla_started_at'] = $lead->sla_started_at ?? $now;
            }
        } elseif ($lead->sla_status === SlaStatus::Frozen) {
            // Keep frozen until scheduled unfreeze; do not force Active here.
        } elseif ($lead->sla_started_at === null && $lead->sla_status === SlaStatus::Active) {
            $updates['sla_started_at'] = $now;
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        $fresh = $lead->fresh() ?? $lead;

        if ($dispatchAssignmentNotification && $fresh->assigned_user_id !== null) {
            SendLeadAssignedNotificationJob::dispatch($fresh->id);
        }

        return $fresh;
    }
}
