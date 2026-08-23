<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Timezones;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * @property-read Schema $form
 */
class CompanyProfilePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Company Profile';

    protected static ?string $title = 'Company Profile';

    protected static ?string $slug = 'company-profile';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.company-profile';

    /** @var array<string, mixed> */
    public array $data = [];

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
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return;
        }

        $tenant = Tenant::query()->find($user->tenant_id);
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if ($tenant === null) {
            return;
        }

        $this->data = [
            'company_name' => $tenant->name,
            'company_logo' => $tenant->logo_url,
            'business_category' => $tenant->business_category,
            'timezone' => $setting instanceof TenantSetting ? $setting->timezone : 'Asia/Riyadh',
        ];
    }

    public function form(Schema $schema): Schema
    {
        $tenantId = auth()->user() instanceof User
            ? (string) (auth()->user()->tenant_id ?? 'shared')
            : 'shared';

        return $schema
            ->components([
                FileUpload::make('company_logo')
                    ->label('Company logo')
                    ->image()
                    ->disk('public')
                    ->directory('logos/'.$tenantId)
                    ->visibility('public')
                    ->maxSize(2048)
                    ->imageEditor()
                    ->nullable(),
                TextInput::make('company_name')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('business_category')
                    ->label('Industry / Business category')
                    ->maxLength(255)
                    ->nullable(),
                Select::make('timezone')
                    ->label('Timezone')
                    ->options(Timezones::options())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Used for SLA deadlines and working hours.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null || ! $user->canManageTenantSettings()) {
            abort(403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->form->getState();

        $logo = $payload['company_logo'] ?? null;

        if (is_array($logo)) {
            $logo = $logo[0] ?? null;
        }

        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        $tenant->update([
            'name' => (string) $payload['company_name'],
            'logo_url' => is_string($logo) ? $logo : null,
            'business_category' => $payload['business_category'] ?? null,
        ]);

        TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first()
            ?->update([
                'timezone' => (string) ($payload['timezone'] ?? 'Asia/Riyadh'),
            ]);

        Notification::make()
            ->title('Company profile saved')
            ->success()
            ->send();
    }
}
