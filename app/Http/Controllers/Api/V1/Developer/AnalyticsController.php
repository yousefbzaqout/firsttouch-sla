<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\SlaStatus;
use App\Http\Resources\Api\SlaAnalyticsResource;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController
{
    public function slaSummary(Request $request): SlaAnalyticsResource
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = (string) $user->tenant_id;

        $totalLeads = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $metCount = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Met)
            ->count();

        $totalBreaches = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sla_status', SlaStatus::Breached)
            ->count();

        $resolved = $metCount + $totalBreaches;
        $complianceRate = $resolved > 0
            ? ($metCount / $resolved) * 100
            : 100.0;

        $averageResponse = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('first_action_at')
            ->whereNotNull('sla_started_at')
            ->select(DB::raw('AVG(EXTRACT(EPOCH FROM (first_action_at - sla_started_at))) as avg_seconds'))
            ->value('avg_seconds');

        return new SlaAnalyticsResource([
            'compliance_rate' => $complianceRate,
            'average_response_seconds' => is_numeric($averageResponse) ? (float) $averageResponse : null,
            'total_breaches' => $totalBreaches,
            'total_leads' => $totalLeads,
            'met_count' => $metCount,
        ]);
    }
}
