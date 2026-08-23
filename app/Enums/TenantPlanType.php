<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantPlanType: string
{
    case Free = 'free';
    case Starter = 'starter';
    case Growth = 'growth';
    case Scale = 'scale';
}
