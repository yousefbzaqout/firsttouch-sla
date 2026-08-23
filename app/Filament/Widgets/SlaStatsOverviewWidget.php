<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Filament\Widgets\Concerns\AuthorizesTenantAnalytics;
use App\Models\Lead;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SlaStatsOverviewWidget extends StatsOverviewWidget
{
    use AuthorizesTenantAnalytics;

    protected static ?int $sort = 1;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return [];
        }

        $tenantId = (string) $user->tenant_id;

        $metLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Met)
            ->count();

        $breachedLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Breached)
            ->count();

        $actionedLeads = $metLeads + $breachedLeads;
        $complianceRate = $actionedLeads > 0
            ? round(($metLeads / $actionedLeads) * 100, 1)
            : 100.0;

        $avgSeconds = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('first_action_at')
            ->whereNotNull('created_at')
            ->select(DB::raw('AVG(EXTRACT(EPOCH FROM (first_action_at - created_at))) as avg_seconds'))
            ->value('avg_seconds');

        $avgResponseLabel = is_numeric($avgSeconds)
            ? self::formatDuration((float) $avgSeconds)
            : '—';

        $monthBreaches = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Breached)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $activeQueue = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [LeadStatus::New, LeadStatus::Claimed])
            ->count();

        $complianceColor = $complianceRate >= 80.0 ? 'success' : 'danger';
        $complianceDescription = $complianceRate >= 80.0
            ? 'On track vs 80% target'
            : 'Below 80% compliance target';

        return [
            Stat::make('SLA Compliance Rate', $complianceRate.'%')
                ->description($complianceDescription)
                ->descriptionIcon($complianceRate >= 80.0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($complianceColor),
            Stat::make('Avg Response Time', $avgResponseLabel)
                ->description('Created → first action')
                ->color('info'),
            Stat::make('Total Breaches', (string) $monthBreaches)
                ->description('This calendar month')
                ->color($monthBreaches > 0 ? 'danger' : 'success'),
            Stat::make('Active Queue', (string) $activeQueue)
                ->description('New + claimed awaiting action')
                ->color('warning'),
        ];
    }

    public static function formatDuration(float $seconds): string
    {
        $total = (int) max(0, round($seconds));
        $minutes = intdiv($total, 60);
        $secs = $total % 60;

        if ($minutes <= 0) {
            return $secs.'s';
        }

        return $minutes.'m '.$secs.'s';
    }
}
