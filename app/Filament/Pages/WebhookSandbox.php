<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\MissingWebhookSecretException;
use App\Filament\Resources\LeadResource;
use App\Models\User;
use App\Services\Simulation\WebhookSimulatorService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * @property-read Schema $form
 */
class WebhookSandbox extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Webhook Sandbox';

    protected static ?string $title = 'Webhook Sandbox';

    protected static ?string $slug = 'webhook-sandbox';

    protected static string|\UnitEnum|null $navigationGroup = 'Tools';

    protected string $view = 'filament.pages.webhook-sandbox';

    /** @var array<string, mixed> */
    public array $data = [
        'source' => 'meta',
        'name' => 'Test Lead',
        'phone' => '+1234567890',
        'inquiry' => 'I am interested in your premium services. What is the price?',
    ];

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
                Select::make('source')
                    ->label('Source')
                    ->options([
                        'meta' => 'Meta',
                        'tiktok' => 'TikTok',
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('name')
                    ->label('Lead name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Phone')
                    ->required()
                    ->maxLength(50),
                Textarea::make('inquiry')
                    ->label('Inquiry')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function simulateWebhook(WebhookSimulatorService $simulator): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            Notification::make()
                ->title('Unable to simulate webhook')
                ->body('Your account is not linked to a tenant.')
                ->danger()
                ->send();

            return;
        }

        /** @var array{source: string, name: string, phone: string, inquiry: string} $payload */
        $payload = $this->form->getState();

        try {
            $ok = $simulator->simulate(
                (string) $user->tenant_id,
                $payload['source'],
                [
                    'name' => $payload['name'],
                    'phone' => $payload['phone'],
                    'inquiry' => $payload['inquiry'],
                ],
            );
        } catch (MissingWebhookSecretException) {
            Notification::make()
                ->title('Webhook secret is missing for this source. Please configure it in Tenant Settings.')
                ->danger()
                ->send();

            return;
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Simulation failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Simulation failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $ok) {
            Notification::make()
                ->title('Simulation failed')
                ->body('The webhook endpoint rejected the signed test payload.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Test lead dispatched successfully!')
            ->success()
            ->actions([
                Action::make('viewLeads')
                    ->label('View Leads')
                    ->url(LeadResource::getUrl('index')),
            ])
            ->send();
    }
}
