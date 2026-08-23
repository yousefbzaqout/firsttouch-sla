<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && $user->tenant_id !== null;
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $record instanceof Lead) {
            return false;
        }

        try {
            app(LeadWorkflowService::class)->assertCanAccessLead($record, $user);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Lead details')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('phone'),
                        TextEntry::make('email'),
                        TextEntry::make('source')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('sla_status')->badge(),
                        TextEntry::make('sla_deadline')->dateTime(),
                        TextEntry::make('assignedUser.name')->label('Assigned To'),
                    ])
                    ->columns(2),
                Section::make('AI assistance')
                    ->schema([
                        TextEntry::make('meta_data.ai_qualification_summary')
                            ->label('AI Qualification Summary')
                            ->placeholder('No AI qualification yet'),
                        TextEntry::make('meta_data.ai_suggested_reply')
                            ->label('AI Suggested Reply')
                            ->placeholder('No suggested reply yet')
                            ->columnSpanFull(),
                        TextEntry::make('meta_data.ai_confidence_score')
                            ->label('AI Qualification Score')
                            ->placeholder('—'),
                        TextEntry::make('meta_data.ai_is_qualified')
                            ->label('AI Qualified')
                            ->formatStateUsing(fn (mixed $state): string => filter_var($state, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No')
                            ->placeholder('—'),
                        TextEntry::make('meta_data.ai_rag_similarity')
                            ->label('KB Match Score')
                            ->placeholder('—'),
                        TextEntry::make('meta_data.ai_routing_mode')
                            ->label('AI Mode Used')
                            ->placeholder('—'),
                        TextEntry::make('ai_quick_replies')
                            ->label('AI Quick Replies')
                            ->formatStateUsing(function (mixed $state): string {
                                if (! is_array($state) || $state === []) {
                                    return '—';
                                }

                                return collect($state)
                                    ->map(function (mixed $row, int $index): string {
                                        if (! is_array($row)) {
                                            return '';
                                        }

                                        $label = (string) ($row['label'] ?? ('Reply '.($index + 1)));

                                        return ($index + 1).'. '.$label;
                                    })
                                    ->filter()
                                    ->implode("\n");
                            })
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (Lead $record): bool => self::shouldShowAiAssistance($record)),
                Section::make('Internal notes')
                    ->schema([
                        TextEntry::make('internal_notes_display')
                            ->label('Notes')
                            ->state(function (Lead $record): string {
                                /** @var list<array{body?: string, user_name?: string, at?: string}> $notes */
                                $notes = is_array($record->meta_data['internal_notes'] ?? null)
                                    ? $record->meta_data['internal_notes']
                                    : [];

                                if ($notes === []) {
                                    return 'No notes yet.';
                                }

                                return collect($notes)
                                    ->map(function (array $note): string {
                                        $author = (string) ($note['user_name'] ?? 'Unknown');
                                        $at = (string) ($note['at'] ?? '');
                                        $body = (string) ($note['body'] ?? '');

                                        return "[{$at}] {$author}: {$body}";
                                    })
                                    ->implode("\n");
                            })
                            ->markdown()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('sla_status')->badge(),
                Tables\Columns\TextColumn::make('sla_deadline')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('assignedUser.name')->label('Assigned To'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(LeadStatus::class),
                Tables\Filters\SelectFilter::make('sla_status')
                    ->options(SlaStatus::class),
                Tables\Filters\SelectFilter::make('source')
                    ->options(LeadSource::class),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('claim')
                    ->label('Claim')
                    ->icon('heroicon-o-hand-raised')
                    ->requiresConfirmation()
                    ->visible(fn (Lead $record): bool => $record->assigned_user_id === null)
                    ->action(function (Lead $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(LeadWorkflowService::class)->claim($record, $actor);
                    }),
                Action::make('markContacted')
                    ->label('Mark Contacted')
                    ->icon('heroicon-o-phone')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(function (Lead $record): bool {
                        $actor = auth()->user();

                        return $actor instanceof User
                            && $record->assigned_user_id !== null
                            && $record->status !== LeadStatus::Contacted
                            && (
                                $actor->canViewAllTenantLeads()
                                || $record->assigned_user_id === $actor->id
                            );
                    })
                    ->action(function (Lead $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(LeadWorkflowService::class)->markContacted($record, $actor);
                    }),
                Action::make('markInProgress')
                    ->label('Mark In Progress')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(function (Lead $record): bool {
                        $actor = auth()->user();

                        return $actor instanceof User
                            && $record->assigned_user_id !== null
                            && $record->status->isAwaitingAgentAction()
                            && (
                                $actor->canViewAllTenantLeads()
                                || $record->assigned_user_id === $actor->id
                            );
                    })
                    ->action(function (Lead $record): void {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return;
                        }

                        app(LeadWorkflowService::class)->markInProgress($record, $actor);
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
                            ->options(fn (): array => self::salesRepOptions())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Lead $record, array $data): void {
                        $actor = auth()->user();
                        $assignee = User::query()->find($data['assigned_user_id'] ?? null);

                        if (! $actor instanceof User || ! $assignee instanceof User) {
                            return;
                        }

                        app(LeadWorkflowService::class)->reassign($record, $assignee, $actor);
                    }),
            ]);
    }

    /** @return Builder<Lead> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Lead> $query */
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->isSalesRep()) {
            return $query->where(function (Builder $builder) use ($user): void {
                $builder
                    ->where('assigned_user_id', $user->id)
                    ->orWhereNull('assigned_user_id');
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'view' => ViewLead::route('/{record}'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function salesRepOptions(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return [];
        }

        return User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('role', UserRole::SalesRep)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function shouldShowAiAssistance(Lead $record): bool
    {
        $meta = $record->meta_data ?? [];

        return filled($meta['ai_suggested_reply'] ?? null)
            || filled($meta['ai_qualification_summary'] ?? null);
    }
}
