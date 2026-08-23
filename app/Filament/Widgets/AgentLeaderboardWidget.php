<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\Concerns\AuthorizesTenantAnalytics;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class AgentLeaderboardWidget extends TableWidget
{
    use AuthorizesTenantAnalytics;

    protected static ?int $sort = 3;

    /**
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Sales Team Performance';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sales Team Performance')
            ->query($this->agentsQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Agent Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('is_online')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state ? 'Online' : 'Offline')
                    ->color(fn (mixed $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('assigned_leads_count')
                    ->label('Assigned Leads')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('closed_deals_count')
                    ->label('Closed Deals')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sla_compliance_rate')
                    ->label('SLA Compliance %')
                    ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 1).'%')
                    ->sortable(),
                TextColumn::make('avg_response_seconds')
                    ->label('Avg Response Time')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_numeric($state)) {
                            return '—';
                        }

                        return SlaStatsOverviewWidget::formatDuration((float) $state);
                    })
                    ->sortable(),
            ])
            ->defaultSort('sla_compliance_rate', 'desc')
            ->paginated(false);
    }

    /**
     * @return Builder<User>
     */
    protected function agentsQuery(): Builder
    {
        $user = auth()->user();
        $tenantId = $user instanceof User ? $user->tenant_id : null;

        if ($tenantId === null) {
            return User::query()->whereRaw('1 = 0');
        }

        $met = SlaStatus::Met->value;
        $breached = SlaStatus::Breached->value;
        $closed = LeadStatus::Closed->value;

        return User::query()
            ->where('users.tenant_id', $tenantId)
            ->where('users.role', UserRole::SalesRep)
            ->where('users.is_active', true)
            ->select('users.*')
            ->selectRaw(
                '(SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ?) AS assigned_leads_count',
                [$tenantId],
            )
            ->selectRaw(
                '(SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.status = ?) AS closed_deals_count',
                [$tenantId, $closed],
            )
            ->selectRaw(
                'CASE
                    WHEN (
                        (SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.sla_status = ?)
                        + (SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.sla_status = ?)
                    ) = 0 THEN 100
                    ELSE (
                        (SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.sla_status = ?) * 100.0
                        / (
                            (SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.sla_status = ?)
                            + (SELECT COUNT(*) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.sla_status = ?)
                        )
                    )
                END AS sla_compliance_rate',
                [$tenantId, $met, $tenantId, $breached, $tenantId, $met, $tenantId, $met, $tenantId, $breached],
            )
            ->selectRaw(
                '(SELECT AVG(EXTRACT(EPOCH FROM (leads.first_action_at - leads.created_at))) FROM leads WHERE leads.assigned_user_id = users.id AND leads.tenant_id = ? AND leads.first_action_at IS NOT NULL) AS avg_response_seconds',
                [$tenantId],
            );
    }
}
