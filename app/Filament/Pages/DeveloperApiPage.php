<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property-read Schema $form
 */
class DeveloperApiPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Developer API';

    protected static ?string $title = 'Developer API Tokens';

    protected static ?string $slug = 'developer-api';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected string $view = 'filament.pages.developer-api';

    /** @var array<string, mixed> */
    public array $data = [
        'name' => 'CRM Integration',
    ];

    public ?string $plainTextToken = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageTenantSettings();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Token name')
                    ->required()
                    ->maxLength(120)
                    ->helperText('A label for this integration token (shown once after creation).'),
            ])
            ->statePath('data');
    }

    public function createToken(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        /** @var array{name: string} $state */
        $state = $this->form->getState();

        $token = $user->createToken($state['name'], ['developer:*']);
        $this->plainTextToken = $token->plainTextToken;

        Notification::make()
            ->title('API token created')
            ->body('Copy the token now — it will not be shown again.')
            ->success()
            ->send();
    }

    public function revokeToken(string $tokenId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $user->tokens()->whereKey($tokenId)->delete();

        Notification::make()
            ->title('Token revoked')
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function getTokensProperty(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return $user->tokens()
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'created_at']);
    }
}
