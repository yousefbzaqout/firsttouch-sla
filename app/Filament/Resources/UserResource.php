<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $modelLabel = 'Team Member';

    protected static ?string $pluralModelLabel = 'Team Members';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageTeam();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageTeam();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->canManageTeam()
            && $record instanceof User
            && self::isManageableTeamMember($record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record)
            && auth()->id() !== $record->getKey();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                Select::make('role')
                    ->options([
                        UserRole::SalesRep->value => 'Sales Rep',
                        UserRole::Admin->value => 'Admin',
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('telegram_chat_id')
                    ->label('Telegram chat ID')
                    ->numeric()
                    ->helperText('Personal Telegram chat ID for instant lead alerts and escalations.')
                    ->nullable(),
                Toggle::make('is_active')
                    ->default(true)
                    ->required(),
                Toggle::make('is_online')
                    ->label('Online for routing')
                    ->helperText('Offline agents are skipped by auto-reassignment and context-aware routing.')
                    ->default(true)
                    ->required(),
                TagsInput::make('skills_tags')
                    ->label('Skills tags')
                    ->helperText('Used for AI context-aware routing (e.g. English, VIP, Technical).')
                    ->placeholder('Add a skill tag')
                    ->suggestions([
                        'English',
                        'Arabic',
                        'VIP',
                        'Budget',
                        'Technical',
                        'Ads',
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role')->badge()->sortable(),
                Tables\Columns\TextColumn::make('telegram_chat_id')
                    ->label('Telegram')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('is_online')
                    ->label('Online')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('skills_tags')
                    ->label('Skills')
                    ->badge()
                    ->separator(',')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                EditAction::make(),
                Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check-circle')
                    ->color(fn (User $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => static::canEdit($record) && auth()->id() !== $record->id)
                    ->action(function (User $record): void {
                        $record->update([
                            'is_active' => ! $record->is_active,
                        ]);
                    }),
                DeleteAction::make(),
            ]);
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<User> $query */
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('role', [UserRole::SalesRep->value, UserRole::Admin->value]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    private static function isManageableTeamMember(User $record): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User || $actor->tenant_id === null) {
            return false;
        }

        if ($record->tenant_id !== $actor->tenant_id) {
            return false;
        }

        return in_array($record->role, [UserRole::SalesRep, UserRole::Admin], true);
    }
}
