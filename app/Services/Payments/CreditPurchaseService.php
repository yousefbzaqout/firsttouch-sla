<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\DTOs\PaymentResponseDTO;
use App\Enums\PaymentLedgerType;
use App\Models\Tenant;
use App\Services\Billing\CreditManagementService;

class CreditPurchaseService
{
    public function __construct(
        private readonly CreditManagementService $creditManagement,
    ) {}

    public function processSuccessfulPayment(string $tenantId, string $driver, PaymentResponseDTO $responseDTO): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return;
        }

        if ($responseDTO->creditsPurchased < 1) {
            return;
        }

        $this->creditManagement->creditLedger(
            tenant: $tenant,
            amount: $responseDTO->creditsPurchased,
            description: 'Credit pack purchase via '.$driver,
            type: PaymentLedgerType::Topup,
            driver: $driver,
            amountPaid: $responseDTO->amountPaid,
            currency: $responseDTO->currency,
            transactionId: $responseDTO->transactionId,
            metadata: [
                'source' => 'payment_webhook',
            ],
        );
    }
}
