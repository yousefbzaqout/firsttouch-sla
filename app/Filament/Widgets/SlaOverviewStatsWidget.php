<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\SlaStatus;
use App\Models\Lead;
use App\Models\TenantSetting;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SlaOverviewStatsWidget extends StatsOverviewWidget
{
    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return [];
        }

        $channel = 'tenant.'.$user->tenant_id;

        return [
            "echo-private:{$channel},.LeadIngestedEvent" => 'refreshStats',
            "echo-private:{$channel},.SlaWarningBroadcastEvent" => 'refreshStats',
            "echo-private:{$channel},.AgentStatusChangedEvent" => 'refreshStats',
        ];
    }

    public function refreshStats(): void
    {
        $this->dispatch('$refresh');
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $tenantId = $user?->tenant_id;

        if (! $tenantId) {
            return [];
        }

        $todayLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', today())
            ->count();

        $totalLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $breachedLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Breached)
            ->count();

        $breachRate = $totalLeads > 0 ? round(($breachedLeads / $totalLeads) * 100, 1) : 0;

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return [
            Stat::make('Leads Today', (string) $todayLeads),
            Stat::make('SLA Breach Rate', "{$breachRate}%"),
            Stat::make('AI Credits', (string) ($setting !== null ? $setting->credits_balance : 0)),
        ];
    }
}
