<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\CompanyProfilePage;
use App\Filament\Pages\CreditTopUpPage;
use App\Filament\Pages\DeveloperApiPage;
use App\Filament\Pages\TenantSettingsPage;
use App\Filament\Pages\WebhookSandbox;
use App\Filament\Widgets\AgentLeaderboardWidget;
use App\Filament\Widgets\LeadsByStatusChartWidget;
use App\Filament\Widgets\LeadSourcesChartWidget;
use App\Filament\Widgets\SlaStatsOverviewWidget;
use App\Livewire\AgentStatusToggle;
use App\Models\User;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('FirstTouch SLA')
            ->darkMode(false)
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->pages([
                Dashboard::class,
                CompanyProfilePage::class,
                TenantSettingsPage::class,
                CreditTopUpPage::class,
                WebhookSandbox::class,
                DeveloperApiPage::class,
            ])
            ->widgets([
                SlaStatsOverviewWidget::class,
                LeadSourcesChartWidget::class,
                AgentLeaderboardWidget::class,
                LeadsByStatusChartWidget::class,
            ])
            ->broadcasting(fn (): bool => filled(config('filament.broadcasting.echo.key')))
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                function (): string {
                    $user = auth()->user();

                    if (! $user instanceof User || ! $user->isSalesRep()) {
                        return '';
                    }

                    return Blade::render(
                        '@livewire(\''.AgentStatusToggle::class.'\')',
                    );
                },
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
