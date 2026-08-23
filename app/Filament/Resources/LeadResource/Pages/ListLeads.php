<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function authorizeAccess(): void
    {
        abort_unless(LeadResource::canViewAny(), 403);
    }

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
            "echo-private:{$channel},.LeadIngestedEvent" => 'onLeadIngested',
            "echo-private:{$channel},.SlaWarningBroadcastEvent" => 'onSlaWarning',
            "echo-private:{$channel},.AgentStatusChangedEvent" => 'onAgentStatusChanged',
        ];
    }

    /**
     * @param  array{lead_id?: string, name?: string, source?: string, created_at?: string|null}  $payload
     */
    public function onLeadIngested(array $payload = []): void
    {
        $this->resetTable();

        Notification::make()
            ->title('New Lead Arrived!')
            ->body(isset($payload['name']) ? (string) $payload['name'] : 'A new lead was ingested.')
            ->success()
            ->send();
    }

    /**
     * @param  array{
     *     lead_id?: string,
     *     assigned_user_name?: string,
     *     threshold_percent?: int,
     *     time_remaining?: int
     * }  $payload
     */
    public function onSlaWarning(array $payload = []): void
    {
        $threshold = (int) ($payload['threshold_percent'] ?? 0);
        $leadId = isset($payload['lead_id']) ? (string) $payload['lead_id'] : null;
        $assignee = (string) ($payload['assigned_user_name'] ?? 'Assigned agent');
        $remaining = (int) ($payload['time_remaining'] ?? 0);

        $notification = Notification::make()
            ->title($threshold >= 80 ? 'SLA 80% breach — reassignment' : 'SLA 50% warning')
            ->body(sprintf(
                '%s · ~%d sec remaining',
                $assignee,
                max(0, $remaining),
            ))
            ->persistent();

        if ($threshold >= 80) {
            $notification->danger();
        } else {
            $notification->warning();
        }

        if ($leadId !== null && $leadId !== '') {
            $notification->actions([
                Action::make('viewLead')
                    ->label('Open lead')
                    ->url(LeadResource::getUrl('view', ['record' => $leadId])),
            ]);
        }

        $notification->send();
        $this->resetTable();
    }

    public function onAgentStatusChanged(): void
    {
        // Keep the command center table fresh when agent availability changes.
        $this->resetTable();
    }
}
