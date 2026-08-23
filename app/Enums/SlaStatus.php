<?php

declare(strict_types=1);

namespace App\Enums;

enum SlaStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Breached = 'breached';
    case Met = 'met';
    case Frozen = 'frozen';
}
