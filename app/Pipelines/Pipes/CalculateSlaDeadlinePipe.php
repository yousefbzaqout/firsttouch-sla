<?php

declare(strict_types=1);

namespace App\Pipelines\Pipes;

use App\DTOs\LeadData;
use App\Enums\AiRoutingMode;
use App\Enums\SlaStatus;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Services\Sla\BusinessHoursService;
use App\Services\Sla\SlaCalculatorService;
use App\Support\Timezones;
use Carbon\Carbon;
use Closure;

class CalculateSlaDeadlinePipe
{
    public function __construct(
        private readonly SlaCalculatorService $calculator,
        private readonly BusinessHoursService $businessHours,
    ) {}

    /**
     * @param  array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null, sla_deadline?: Carbon|null, sla_status?: SlaStatus|null, sla_started_at?: Carbon|null}  $passable
     * @return array{lead_data: LeadData, lead: Lead|null, assigned_user_id?: int|null, sla_deadline?: Carbon|null, sla_status?: SlaStatus|null, sla_started_at?: Carbon|null}
     */
    public function handle(array $passable, Closure $next): array
    {
        $tenantId = $passable['lead_data']->tenantId;
        $mode = $this->routingMode($tenantId);

        // AI First: SLA starts only after qualification + assignment.
        if ($mode === AiRoutingMode::AiFirst) {
            $passable['sla_deadline'] = null;
            $passable['sla_status'] = SlaStatus::Pending;
            $passable['sla_started_at'] = null;

            return $next($passable);
        }

        $setting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $slaMinutes = $setting !== null ? $setting->sla_timeout_minutes : 5;
        $timezone = Timezones::resolve(
            is_string($setting?->timezone) ? $setting->timezone : null,
        );

        $workingHours = TenantWorkingHour::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();

        $tenant = Tenant::query()->find($tenantId);
        $now = Carbon::now();

        if ($tenant !== null && ! $this->businessHours->isWorkingHour($tenant, $now)) {
            $slaStart = $this->businessHours->getNextWorkingHourStart($tenant, $now);
            $passable['sla_deadline'] = $this->calculator->calculate(
                $slaStart->copy(),
                $slaMinutes,
                $workingHours,
                $timezone,
            );
            $passable['sla_status'] = SlaStatus::Frozen;
            $passable['sla_started_at'] = $slaStart;

            return $next($passable);
        }

        $passable['sla_deadline'] = $this->calculator->calculate(
            $now->copy(),
            $slaMinutes,
            $workingHours,
            $timezone,
        );
        $passable['sla_status'] = SlaStatus::Active;
        $passable['sla_started_at'] = $now;

        return $next($passable);
    }

    private function routingMode(string $tenantId): AiRoutingMode
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return $setting !== null ? $setting->ai_routing_mode : AiRoutingMode::HumanFirst;
    }
}
