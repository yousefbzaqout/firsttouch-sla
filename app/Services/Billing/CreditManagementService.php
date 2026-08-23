<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\AiRoutingMode;
use App\Enums\PaymentLedgerType;
use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CreditManagementService
{
    public function __construct(
        private readonly NotificationDriverFactory $notificationFactory,
    ) {}

    public function hasEnoughCredits(Tenant $tenant, int $required = 1): bool
    {
        if ($required < 1) {
            return true;
        }

        $setting = $this->getSetting($tenant);

        return $setting !== null && $setting->credits_balance >= $required;
    }

    public function deductCredits(Tenant $tenant, int $amount, string $description): bool
    {
        if ($amount < 1) {
            return false;
        }

        if (! $this->hasEnoughCredits($tenant, $amount)) {
            $this->handleExhaustedCredits($tenant);

            return false;
        }

        $deducted = false;

        DB::transaction(function () use ($tenant, $amount, $description, &$deducted): void {
            $setting = TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            if ($setting === null || $setting->credits_balance < $amount) {
                return;
            }

            $setting->decrement('credits_balance', $amount);

            PaymentTransaction::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'transaction_id' => 'ai-deduct-'.Str::uuid()->toString(),
                'driver' => 'system',
                'type' => PaymentLedgerType::AiDeduction,
                'description' => $description,
                'amount' => 0,
                'currency' => 'USD',
                'credits_added' => -$amount,
                'status' => PaymentStatus::Completed,
                'metadata' => [
                    'reason' => $description,
                ],
            ]);

            $deducted = true;
        });

        if (! $deducted) {
            $this->handleExhaustedCredits($tenant);

            return false;
        }

        $fresh = $this->getSetting($tenant);

        if ($fresh === null) {
            return true;
        }

        if ($fresh->credits_balance === 0) {
            $this->handleExhaustedCredits($tenant);

            return true;
        }

        $this->maybeNotifyLowCredits($tenant, $fresh);

        return true;
    }

    public function addCredits(Tenant $tenant, int $amount, string $description): PaymentTransaction
    {
        return $this->creditLedger(
            tenant: $tenant,
            amount: $amount,
            description: $description,
            type: PaymentLedgerType::Topup,
            driver: 'system',
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function creditLedger(
        Tenant $tenant,
        int $amount,
        string $description,
        PaymentLedgerType $type,
        string $driver,
        float $amountPaid = 0.0,
        string $currency = 'USD',
        ?string $transactionId = null,
        array $metadata = [],
        bool $notify = true,
    ): PaymentTransaction {
        if ($amount < 1) {
            throw new RuntimeException('Credits to add must be at least 1.');
        }

        $transactionId ??= $type->value.'-'.Str::uuid()->toString();

        $existing = PaymentTransaction::withoutGlobalScopes()
            ->where('transaction_id', $transactionId)
            ->first();

        if ($existing !== null && $existing->status === PaymentStatus::Completed) {
            return $existing;
        }

        $restoredFromHumanOnly = false;
        $newBalance = 0;
        $notificationDriver = 'telegram';

        $transaction = DB::transaction(function () use (
            $tenant,
            $amount,
            $description,
            $type,
            $driver,
            $amountPaid,
            $currency,
            $transactionId,
            $metadata,
            &$restoredFromHumanOnly,
            &$newBalance,
            &$notificationDriver,
        ): PaymentTransaction {
            $payment = PaymentTransaction::withoutGlobalScopes()->updateOrCreate(
                ['transaction_id' => $transactionId],
                [
                    'tenant_id' => $tenant->id,
                    'driver' => $driver,
                    'type' => $type,
                    'description' => $description,
                    'amount' => $amountPaid,
                    'currency' => strtoupper($currency),
                    'credits_added' => $amount,
                    'status' => PaymentStatus::Completed,
                    'metadata' => $metadata,
                ],
            );

            $setting = TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();

            if ($setting === null) {
                throw new RuntimeException("Tenant settings missing for tenant {$tenant->id}.");
            }

            $notificationDriver = $setting->notification_driver;
            $setting->increment('credits_balance', $amount);
            $setting->refresh();
            $newBalance = $setting->credits_balance;

            if ($setting->ai_routing_mode === AiRoutingMode::HumanOnly) {
                $setting->update(['ai_routing_mode' => AiRoutingMode::AiFirst]);
                $restoredFromHumanOnly = true;
            }

            return $payment->fresh() ?? $payment;
        });

        $this->clearLowCreditsNotification($tenant->id);

        if ($notify) {
            $statusMessage = "Credits restored for {$tenant->name}: +{$amount}. New balance: {$newBalance}.";

            if ($restoredFromHumanOnly) {
                $statusMessage .= ' AI routing switched back to ai_first.';
            }

            $this->notificationFactory
                ->resolve($notificationDriver)
                ->sendAccountStatusAlert($tenant->id, $statusMessage);

            $this->notifyOwnersFilament($tenant, 'Credits Added', $statusMessage);
        }

        return $transaction;
    }

    public function handleExhaustedCredits(Tenant $tenant): void
    {
        $setting = $this->getSetting($tenant);

        if ($setting === null) {
            return;
        }

        $setting->update(['ai_routing_mode' => AiRoutingMode::HumanOnly]);

        Log::warning('Tenant credits exhausted, switching to human_only', [
            'tenant_id' => $tenant->id,
        ]);

        $message = "Low Credit Balance: AI credits exhausted for tenant {$tenant->name}. Routing switched to human_only. Top up credits to restore AI assistance.";

        $this->notificationFactory
            ->resolve($setting->notification_driver)
            ->sendAccountStatusAlert($tenant->id, $message);

        $this->notifyOwnersFilament($tenant, 'Low Credit Balance', $message);

        Cache::forget($this->lowCreditsCacheKey($tenant->id));
    }

    public function clearLowCreditsNotification(string $tenantId): void
    {
        Cache::forget($this->lowCreditsCacheKey($tenantId));
    }

    private function maybeNotifyLowCredits(Tenant $tenant, TenantSetting $setting): void
    {
        $threshold = max(1, (int) config('services.telegram.low_credits_threshold', 10));
        $balance = $setting->credits_balance;

        if ($balance <= 0 || $balance > $threshold) {
            if ($balance > $threshold) {
                Cache::forget($this->lowCreditsCacheKey($tenant->id));
            }

            return;
        }

        $cacheKey = $this->lowCreditsCacheKey($tenant->id);

        if (Cache::has($cacheKey)) {
            return;
        }

        $message = "Low Credit Balance for {$tenant->name}: {$balance} credit(s) remaining (threshold: {$threshold}). Top up soon to avoid AI routing interruption.";

        $sent = $this->notificationFactory
            ->resolve($setting->notification_driver)
            ->sendAccountStatusAlert($tenant->id, $message);

        $this->notifyOwnersFilament($tenant, 'Low Credit Balance', $message);

        if ($sent) {
            Cache::forever($cacheKey, true);
        }
    }

    private function notifyOwnersFilament(Tenant $tenant, string $title, string $body): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $recipients = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->canManageTenantSettings());

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->warning()
                ->sendToDatabase($recipient);
        }
    }

    private function lowCreditsCacheKey(string $tenantId): string
    {
        return "tenant:{$tenantId}:low_credits_notified";
    }

    private function getSetting(Tenant $tenant): ?TenantSetting
    {
        return TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();
    }
}
