<?php

declare(strict_types=1);

namespace App\Enums;

enum OffHoursAction: string
{
    case FreezeSla = 'freeze_sla';
    case AiAutoRespond = 'ai_auto_respond';
}
