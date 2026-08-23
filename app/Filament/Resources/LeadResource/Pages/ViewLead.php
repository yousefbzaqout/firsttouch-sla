<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label('Claim Lead')
                ->icon('heroicon-o-hand-raised')
                ->visible(fn (): bool => $this->getLead()->assigned_user_id === null)
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(LeadWorkflowService::class)->claim($this->getLead(), $actor);
                    $this->refreshFormData(['assigned_user_id', 'status', 'claimed_at']);
                }),
            Action::make('markContacted')
                ->label('Mark Contacted')
                ->icon('heroicon-o-phone')
                ->color('success')
                ->visible(function (): bool {
                    $lead = $this->getLead();
                    $actor = auth()->user();

                    return $actor instanceof User
                        && $lead->assigned_user_id !== null
                        && $lead->status !== LeadStatus::Contacted
                        && (
                            $actor->canViewAllTenantLeads()
                            || $lead->assigned_user_id === $actor->id
                        );
                })
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(LeadWorkflowService::class)->markContacted($this->getLead(), $actor);
                    $this->refreshFormData(['status', 'sla_status', 'first_action_at']);
                }),
            Action::make('updateStatus')
                ->label('Update Status')
                ->icon('heroicon-o-pencil-square')
                ->visible(function (): bool {
                    $lead = $this->getLead();
                    $actor = auth()->user();

                    return $actor instanceof User
                        && (
                            $actor->canViewAllTenantLeads()
                            || $lead->assigned_user_id === $actor->id
                        );
                })
                ->form([
                    Select::make('status')
                        ->options([
                            LeadStatus::Claimed->value => 'Claimed',
                            LeadStatus::InProgress->value => 'In Progress',
                            LeadStatus::Contacted->value => 'Contacted',
                            LeadStatus::Closed->value => 'Closed',
                            LeadStatus::Lost->value => 'Lost',
                        ])
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $status = LeadStatus::tryFrom((string) ($data['status'] ?? ''));

                    if (! $actor instanceof User || $status === null) {
                        return;
                    }

                    app(LeadWorkflowService::class)->updateStatus($this->getLead(), $status, $actor);
                    $this->refreshFormData(['status', 'sla_status', 'first_action_at']);
                }),
            Action::make('addNote')
                ->label('Add Note')
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->visible(function (): bool {
                    $lead = $this->getLead();
                    $actor = auth()->user();

                    return $actor instanceof User
                        && (
                            $actor->canViewAllTenantLeads()
                            || $lead->assigned_user_id === $actor->id
                        );
                })
                ->form([
                    Textarea::make('note')
                        ->label('Internal note')
                        ->required()
                        ->rows(4),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        return;
                    }

                    app(LeadWorkflowService::class)->addInternalNote(
                        $this->getLead(),
                        (string) ($data['note'] ?? ''),
                        $actor,
                    );
                    $this->refreshFormData(['meta_data']);
                }),
            Action::make('reassign')
                ->label('Reassign')
                ->icon('heroicon-o-arrow-path')
                ->visible(function (): bool {
                    $actor = auth()->user();

                    return $actor instanceof User && $actor->canViewAllTenantLeads();
                })
                ->form([
                    Select::make('assigned_user_id')
                        ->label('Sales Rep')
                        ->options(fn (): array => LeadResource::salesRepOptions())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $assignee = User::query()->find($data['assigned_user_id'] ?? null);

                    if (! $actor instanceof User || ! $assignee instanceof User) {
                        return;
                    }

                    app(LeadWorkflowService::class)->reassign($this->getLead(), $assignee, $actor);
                    $this->refreshFormData(['assigned_user_id', 'status', 'claimed_at']);
                }),
        ];
    }

    private function getLead(): Lead
    {
        /** @var Lead $lead */
        $lead = $this->getRecord();

        return $lead;
    }
}
