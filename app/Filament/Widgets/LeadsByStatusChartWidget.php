<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Widgets\Concerns\AuthorizesTenantAnalytics;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;

class LeadsByStatusChartWidget extends ChartWidget
{
    use AuthorizesTenantAnalytics;

    protected ?string $heading = 'Leads by Status';

    protected static ?int $sort = 4;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $user = auth()->user();
        $tenantId = $user?->tenant_id;

        if (! $tenantId) {
            return ['datasets' => [], 'labels' => []];
        }

        $counts = [];
        $labels = [];

        foreach (LeadStatus::cases() as $status) {
            $count = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', $status)
                ->count();

            $counts[] = $count;
            $labels[] = $status->value;
        }

        return [
            'datasets' => [
                [
                    'data' => $counts,
                    'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b', '#6366f1', '#ef4444'],
                ],
            ],
            'labels' => $labels,
        ];
    }
}
