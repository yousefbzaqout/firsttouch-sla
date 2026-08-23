<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Claimed = 'claimed';
    case InProgress = 'in_progress';
    case Contacted = 'contacted';
    case Closed = 'closed';
    case Lost = 'lost';

    public function stopsSlaBuffers(): bool
    {
        return match ($this) {
            self::InProgress, self::Contacted, self::Closed, self::Lost => true,
            self::New, self::Claimed => false,
        };
    }

    public function isAwaitingAgentAction(): bool
    {
        return match ($this) {
            self::New, self::Claimed => true,
            self::InProgress, self::Contacted, self::Closed, self::Lost => false,
        };
    }
}
