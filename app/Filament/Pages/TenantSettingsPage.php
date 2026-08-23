<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AiRoutingMode;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\Timezones;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * @property-read Schema $form
 */
class TenantSettingsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Tenant Settings';

    protected static ?string $slug = 'tenant-settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.tenant-settings';

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

        if (! $user || ! $user->tenant_id) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if ($setting) {
            $this->data = [
                'sla_timeout_minutes' => $setting->sla_timeout_minutes,
                'ai_confidence_threshold' => $setting->ai_confidence_threshold,
                'ai_selected_model' => $setting->ai_selected_model,
                'timezone' => $setting->timezone ?? 'Asia/Riyadh',
                'ai_routing_mode' => $setting->ai_routing_mode->value,
                'notification_driver' => $setting->notification_driver,
                'telegram_chat_id' => $setting->telegram_chat_id,
                // Do not hydrate existing secrets into the form (write-only update).
                'telegram_bot_token' => null,
                'meta_webhook_secret' => null,
                'tiktok_webhook_secret' => null,
                'google_webhook_secret' => null,
                'snapchat_webhook_secret' => null,
                'universal_webhook_secret' => null,
                'website_api_key' => null,
                'outbound_webhook_url' => $setting->outbound_webhook_url,
                'outbound_webhook_secret' => null,
            ];
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sla_timeout_minutes')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                TextInput::make('ai_confidence_threshold')
                    ->numeric()
                    ->required(),
                TextInput::make('ai_selected_model')
                    ->required(),
                Select::make('ai_routing_mode')
                    ->label('AI routing mode')
                    ->options(collect(AiRoutingMode::cases())->mapWithKeys(
                        fn (AiRoutingMode $mode): array => [$mode->value => $mode->getLabel()],
                    )->all())
                    ->helperText(fn (?string $state): ?string => AiRoutingMode::tryFrom((string) $state)?->getDescription())
                    ->required()
                    ->native(false)
                    ->live(),
                Select::make('timezone')
                    ->label('Timezone')
                    ->options(Timezones::options())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Must be a valid IANA timezone (e.g. Asia/Riyadh). Used for SLA and working hours.'),
                Select::make('notification_driver')
                    ->label('Notification Driver')
                    ->options([
                        'n8n' => 'n8n',
                        'telegram' => 'telegram',
                        'log' => 'log',
                    ])
                    ->required()
                    ->live()
                    ->native(false),
                TextInput::make('telegram_bot_token')
                    ->label('Telegram bot token')
                    ->password()
                    ->revealable()
                    ->helperText('Optional. Leave blank to use the default platform bot.')
                    ->visible(fn (Get $get): bool => in_array($get('notification_driver'), ['telegram', 'n8n'], true)),
                TextInput::make('telegram_chat_id')
                    ->label('Telegram chat ID')
                    ->helperText('Group or Channel ID for alerts, e.g., -100123456789')
                    ->required(fn (Get $get): bool => $get('notification_driver') === 'telegram')
                    ->visible(fn (Get $get): bool => in_array($get('notification_driver'), ['telegram', 'n8n'], true)),
                TextInput::make('meta_webhook_secret')
                    ->label('Meta webhook secret')
                    ->password()
                    ->revealable()
                    ->helperText('Paste your Meta App webhook HMAC secret. Leave blank to keep the current value or fall back to the platform default.'),
                TextInput::make('tiktok_webhook_secret')
                    ->label('TikTok webhook secret')
                    ->password()
                    ->revealable()
                    ->helperText('Paste your TikTok webhook HMAC secret. Leave blank to keep the current value or fall back to the platform default.'),
                TextInput::make('google_webhook_secret')
                    ->label('Google Ads webhook secret')
                    ->password()
                    ->revealable()
                    ->helperText('Paste the google_key value from your Google Ads lead form webhook. Leave blank to keep the current value or fall back to the platform default.'),
                TextInput::make('snapchat_webhook_secret')
                    ->label('Snapchat Ads webhook secret')
                    ->password()
                    ->revealable()
                    ->helperText('Paste your Snapchat lead form secret (X-Snapchat-Signature / snapchat_key). Leave blank to keep the current value or fall back to the platform default.'),
                TextInput::make('universal_webhook_secret')
                    ->label('Universal webhook secret (Zapier / Make)')
                    ->password()
                    ->revealable()
                    ->helperText('Shared secret for Zapier, Make, n8n, or Pabbly. Send via X-Universal-Secret, X-Zapier-Secret, Authorization Bearer, or ?secret=. Leave blank to keep the current value.'),
                TextInput::make('website_api_key')
                    ->label('Website form API key')
                    ->password()
                    ->revealable()
                    ->helperText('API key for custom websites / WordPress forms. Send via X-Website-Api-Key, X-Api-Key, Authorization Bearer, or ?api_key=. Leave blank to keep the current value. Generate Key: use a long random string such as ft_web_…'),
                TextInput::make('outbound_webhook_url')
                    ->label('Outbound webhook URL')
                    ->url()
                    ->nullable()
                    ->helperText('Your CRM/ERP endpoint that receives lead status updates (POST JSON).'),
                TextInput::make('outbound_webhook_secret')
                    ->label('Outbound webhook secret')
                    ->password()
                    ->revealable()
                    ->helperText('Optional HMAC secret. Sent as X-FirstTouch-Signature. Leave blank to keep the current value.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $user = auth()->user();

        if (! $user || ! $user->tenant_id) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $this->form->getState();

        $timezone = (string) ($payload['timezone'] ?? '');

        if (! Timezones::isValid($timezone)) {
            $this->addError('data.timezone', 'The selected timezone is invalid.');

            return;
        }

        $metaSecret = $payload['meta_webhook_secret'] ?? null;
        $tiktokSecret = $payload['tiktok_webhook_secret'] ?? null;
        $googleSecret = $payload['google_webhook_secret'] ?? null;
        $snapchatSecret = $payload['snapchat_webhook_secret'] ?? null;
        $universalSecret = $payload['universal_webhook_secret'] ?? null;
        $websiteApiKey = $payload['website_api_key'] ?? null;
        $telegramToken = $payload['telegram_bot_token'] ?? null;
        $outboundSecret = $payload['outbound_webhook_secret'] ?? null;

        unset(
            $payload['meta_webhook_secret'],
            $payload['tiktok_webhook_secret'],
            $payload['google_webhook_secret'],
            $payload['snapchat_webhook_secret'],
            $payload['universal_webhook_secret'],
            $payload['website_api_key'],
            $payload['telegram_bot_token'],
            $payload['outbound_webhook_secret'],
        );

        if (is_string($metaSecret) && $metaSecret !== '') {
            $payload['meta_webhook_secret'] = $metaSecret;
        }

        if (is_string($tiktokSecret) && $tiktokSecret !== '') {
            $payload['tiktok_webhook_secret'] = $tiktokSecret;
        }

        if (is_string($googleSecret) && $googleSecret !== '') {
            $payload['google_webhook_secret'] = $googleSecret;
        }

        if (is_string($snapchatSecret) && $snapchatSecret !== '') {
            $payload['snapchat_webhook_secret'] = $snapchatSecret;
        }

        if (is_string($universalSecret) && $universalSecret !== '') {
            $payload['universal_webhook_secret'] = $universalSecret;
        }

        if (is_string($websiteApiKey) && $websiteApiKey !== '') {
            $payload['website_api_key'] = $websiteApiKey;
        }

        if (is_string($telegramToken) && $telegramToken !== '') {
            $payload['telegram_bot_token'] = $telegramToken;
        }

        if (is_string($outboundSecret) && $outboundSecret !== '') {
            $payload['outbound_webhook_secret'] = $outboundSecret;
        }

        if (array_key_exists('telegram_chat_id', $payload) && $payload['telegram_chat_id'] === '') {
            $payload['telegram_chat_id'] = null;
        }

        if (array_key_exists('outbound_webhook_url', $payload) && $payload['outbound_webhook_url'] === '') {
            $payload['outbound_webhook_url'] = null;
        }

        TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first()
            ?->update($payload);

        $this->data['meta_webhook_secret'] = null;
        $this->data['tiktok_webhook_secret'] = null;
        $this->data['google_webhook_secret'] = null;
        $this->data['snapchat_webhook_secret'] = null;
        $this->data['universal_webhook_secret'] = null;
        $this->data['website_api_key'] = null;
        $this->data['telegram_bot_token'] = null;
        $this->data['outbound_webhook_secret'] = null;

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function generateWebsiteApiKey(): void
    {
        $this->data['website_api_key'] = 'ft_web_'.Str::lower(Str::random(40));

        Notification::make()
            ->title('Website API key generated')
            ->body('Save changes to store the new key. Copy it now — it will not be shown again after save.')
            ->success()
            ->send();
    }
}
