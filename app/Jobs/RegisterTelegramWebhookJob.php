<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RegisterTelegramWebhookJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public function __construct(
        private readonly string $tenantId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        $token = is_string($setting?->telegram_bot_token)
            ? trim($setting->telegram_bot_token)
            : '';

        if ($token === '') {
            Log::info('RegisterTelegramWebhookJob skipped: empty bot token', [
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        $webhookUrl = $this->resolveWebhookUrl();
        $secret = trim((string) config('services.telegram.webhook_secret', ''));

        /** @var array<string, mixed> $payload */
        $payload = [
            'url' => $webhookUrl,
            'allowed_updates' => ['callback_query'],
            'drop_pending_updates' => false,
        ];

        if ($secret !== '') {
            $payload['secret_token'] = $secret;
        }

        try {
            $response = Http::timeout(15)
                ->asForm()
                ->post('https://api.telegram.org/bot'.$token.'/setWebhook', $payload);
        } catch (Throwable $exception) {
            Log::error('Telegram webhook registration exception', [
                'tenant_id' => $this->tenantId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($response->successful() && ($response->json('ok') === true)) {
            Log::info('Telegram webhook registered', [
                'tenant_id' => $this->tenantId,
                'url' => $webhookUrl,
                'description' => $response->json('description'),
            ]);

            return;
        }

        Log::warning('Telegram webhook registration failed', [
            'tenant_id' => $this->tenantId,
            'url' => $webhookUrl,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        // Retry only on transient upstream failures.
        if ($response->serverError()) {
            throw new RuntimeException('Telegram setWebhook failed with HTTP '.$response->status());
        }
    }

    private function resolveWebhookUrl(): string
    {
        $configuredRoot = rtrim((string) config('app.url'), '/');
        $path = '/api/v1/webhooks/telegram/'.$this->tenantId;

        if ($configuredRoot !== '') {
            $url = $configuredRoot.$path;
        } else {
            $url = route('webhooks.telegram', ['tenant_id' => $this->tenantId], absolute: true);
        }

        if (app()->environment('production') && str_starts_with($url, 'http://')) {
            return (string) preg_replace('/^http:\/\//', 'https://', $url, 1);
        }

        return $url;
    }
}
