<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantPlanType;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Billing\StripeBillingService;
use App\Services\Payments\PaymentManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class CreditTopUpPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Billing & Credits';

    protected static ?string $title = 'Billing & Credits';

    protected static ?string $slug = 'credit-top-up';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.credit-top-up';

    public int $creditsBalance = 0;

    public string $aiRoutingMode = '';

    public string $planType = 'free';

    public string $subscriptionStatus = 'active';

    /** @var array<int, array{id: string, type: string, description: string|null, credits_added: int, amount: string, status: string, created_at: string}> */
    public array $transactions = [];

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

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->first();

        $this->creditsBalance = $setting !== null ? $setting->credits_balance : 0;
        $this->aiRoutingMode = $setting !== null ? $setting->ai_routing_mode->value : 'human_only';
        $this->planType = $setting?->plan_type instanceof TenantPlanType
            ? $setting->plan_type->value
            : TenantPlanType::Free->value;
        $this->subscriptionStatus = $setting?->subscription_status instanceof SubscriptionStatus
            ? $setting->subscription_status->value
            : SubscriptionStatus::Active->value;

        $this->transactions = PaymentTransaction::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->latest()
            ->limit(25)
            ->get()
            ->map(static function (PaymentTransaction $tx): array {
                return [
                    'id' => (string) $tx->id,
                    'type' => (string) $tx->type->value,
                    'description' => $tx->description,
                    'credits_added' => $tx->credits_added,
                    'amount' => (string) $tx->amount,
                    'status' => (string) $tx->status->value,
                    'created_at' => $tx->created_at?->toDateTimeString() ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('buyCredits')
                ->label('Buy Credits / Top-Up')
                ->icon('heroicon-o-shopping-cart')
                ->modalHeading('Buy Credits / Top-Up')
                ->modalDescription('Choose a credit package. You will be redirected to Stripe Checkout to complete payment.')
                ->form([
                    Radio::make('package')
                        ->label('Credit package')
                        ->options($this->packageOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $key = (string) ($data['package'] ?? '');
                    [$credits, $amount] = array_map('floatval', explode(':', $key) + [0, 0]);

                    $this->purchaseCredits((int) $credits, (float) $amount);
                }),
        ];
    }

    public function purchaseCredits(int $credits, float $amount): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->tenant_id === null) {
            return;
        }

        $tenant = Tenant::query()->find($user->tenant_id);

        if ($tenant === null) {
            return;
        }

        $driver = (string) config('payments.default_driver', 'mock');

        try {
            if ($driver === 'stripe') {
                $checkoutUrl = app(StripeBillingService::class)->createCheckoutSession(
                    $tenant,
                    $credits,
                    url('/admin/credit-top-up?checkout=success'),
                    url('/admin/credit-top-up?checkout=cancelled'),
                );
            } else {
                $checkoutUrl = app(PaymentManager::class)
                    ->resolve()
                    ->createCheckoutSession($tenant, $credits, $amount);
            }
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Checkout unavailable')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->redirect($checkoutUrl, navigate: false);
    }

    /**
     * @return array<string, string>
     */
    private function packageOptions(): array
    {
        /** @var list<array{credits: int, amount: float|int|string, label?: string}> $packages */
        $packages = config('payments.packages', []);

        $options = [];

        foreach ($packages as $package) {
            $credits = (int) $package['credits'];
            $amount = (float) $package['amount'];
            $label = (string) ($package['label'] ?? "{$credits} credits");
            $key = $credits.':'.$amount;
            $options[$key] = sprintf('%s — $%s', $label, number_format($amount, 2));
        }

        return $options;
    }

    /**
     * @return Collection<int, array{credits: int, amount: float, label: string}>
     */
    public function packages(): Collection
    {
        /** @var list<array{credits: int, amount: float|int|string, label?: string}> $packages */
        $packages = config('payments.packages', []);

        return collect($packages)->map(static fn (array $package): array => [
            'credits' => (int) $package['credits'],
            'amount' => (float) $package['amount'],
            'label' => (string) ($package['label'] ?? ((int) $package['credits'].' credits')),
        ]);
    }
}
