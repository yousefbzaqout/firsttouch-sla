<?php

declare(strict_types=1);

namespace App\Services\Telegram;

readonly class ResolvedTelegramDestination
{
    public function __construct(
        public string $botToken,
        public ?string $chatId,
        public bool $usedFallbackToken,
    ) {}

    public function canSend(): bool
    {
        return $this->botToken !== ''
            && $this->chatId !== null
            && $this->chatId !== '';
    }
}
