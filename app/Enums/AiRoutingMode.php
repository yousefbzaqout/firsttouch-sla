<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum AiRoutingMode: string implements HasDescription, HasLabel
{
    case AiFirst = 'ai_first';
    case HumanFirst = 'human_first';
    case AiAssisted = 'ai_assisted';
    case HumanOnly = 'human_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::AiFirst => 'AI First',
            self::HumanFirst => 'Human First',
            self::AiAssisted => 'AI Assistance',
            self::HumanOnly => 'No AI (Human Only)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::AiFirst => 'AI qualifies the lead first; assignment and SLA start only if qualified.',
            self::HumanFirst => 'Skip AI filtering — assign immediately and start the SLA timer.',
            self::AiAssisted => 'Assign immediately and start SLA; AI prepares a suggested reply in the background.',
            self::HumanOnly => 'No AI processing. Used manually or automatically when credits are exhausted.',
        };
    }

    public function runsAi(): bool
    {
        return match ($this) {
            self::AiFirst, self::AiAssisted => true,
            self::HumanFirst, self::HumanOnly => false,
        };
    }

    public function assignsBeforeAi(): bool
    {
        return match ($this) {
            self::AiFirst => false,
            self::HumanFirst, self::AiAssisted, self::HumanOnly => true,
        };
    }
}
