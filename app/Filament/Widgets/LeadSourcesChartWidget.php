<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadSource;
use App\Filament\Widgets\Concerns\AuthorizesTenantAnalytics;
use App\Models\Lead;
use App\Models\User;
use Filament\Widgets\ChartWidget;

class LeadSourcesChartWidget extends ChartWidget
{
    use AuthorizesTenantAnalytics;

    protected ?string $heading = 'Leads by Source';

    protected static ?int $sort = 2;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return ['datasets' => [], 'labels' => []];
        }

        $tenantId = (string) $user->tenant_id;
        $since = now()->subDays(30);

        $countsBySource = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->selectRaw('source, COUNT(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source');

        $labels = [];
        $data = [];
        $colors = [];

        foreach (LeadSource::cases() as $source) {
            $labels[] = ucfirst($source->value);
            $data[] = (int) ($countsBySource[$source->value] ?? 0);
            $colors[] = self::colorForSource($source);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    private static function colorForSource(LeadSource $source): string
    {
        return match ($source) {
            LeadSource::Meta => '#1877F2',
            LeadSource::TikTok => '#010101',
            LeadSource::Google => '#EA4335',
            LeadSource::Snapchat => '#FFFC00',
            LeadSource::Universal => '#7C3AED',
            LeadSource::Website => '#0EA5E9',
            LeadSource::Manual => '#64748B',
        };
    }
}
