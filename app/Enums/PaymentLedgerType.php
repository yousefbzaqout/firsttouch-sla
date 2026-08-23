<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentLedgerType: string
{
    case Topup = 'topup';
    case MonthlySubscription = 'monthly_subscription';
    case AiDeduction = 'ai_deduction';
}
