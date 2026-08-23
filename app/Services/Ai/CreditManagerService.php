<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Tenant;
use App\Services\Billing\CreditManagementService;

/**
 * Thin AI-layer facade over {@see CreditManagementService}.
 */
class CreditManagerService
{
    public function __construct(
        private readonly CreditManagementService $creditManagement,
    ) {}

    public function hasAvailableCredits(Tenant $tenant): bool
    {
        return $this->creditManagement->hasEnoughCredits($tenant, 1);
    }

    public function deductCredit(Tenant $tenant): void
    {
        $this->creditManagement->deductCredits($tenant, 1, 'AI response inference');
    }

    public function handleExhaustedCredits(Tenant $tenant): void
    {
        $this->creditManagement->handleExhaustedCredits($tenant);
    }

    public function clearLowCreditsNotification(string $tenantId): void
    {
        $this->creditManagement->clearLowCreditsNotification($tenantId);
    }
}
