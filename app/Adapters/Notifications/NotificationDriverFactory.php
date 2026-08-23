<?php

declare(strict_types=1);

namespace App\Adapters\Notifications;

use App\Adapters\Notifications\Contracts\NotificationDriverInterface;
use App\Services\Telegram\TelegramMessageBuilder;
use App\Services\Telegram\TelegramNotifier;

class NotificationDriverFactory
{
    public function __construct(
        private readonly TelegramNotifier $telegramNotifier,
        private readonly TelegramMessageBuilder $telegramMessageBuilder,
    ) {}

    public function resolve(string $driver): NotificationDriverInterface
    {
        return match ($driver) {
            'telegram' => new TelegramNotificationDriver(
                $this->telegramNotifier,
                $this->telegramMessageBuilder,
            ),
            'n8n' => new N8nNotificationDriver(
                (string) config('services.n8n.webhook_url', ''),
            ),
            default => new LogNotificationDriver,
        };
    }
}
