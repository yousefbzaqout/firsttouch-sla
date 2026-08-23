<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Tenant;
use App\Services\Telegram\TelegramQuickReplyCallbackHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController
{
    public function __invoke(
        Request $request,
        string $tenantId,
        TelegramQuickReplyCallbackHandler $callbackHandler,
    ): JsonResponse {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! $tenant->is_active) {
            return response()->json(['error' => 'tenant_not_found'], 404);
        }

        if (! $this->passesSecretCheck($request, $tenantId)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        /** @var array<string, mixed> $update */
        $update = $request->all();

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $result = $callbackHandler->handle($tenantId, $update['callback_query']);

            Log::info('Telegram callback processed', [
                'tenant_id' => $tenantId,
                'ok' => $result['ok'],
                'error' => $result['error'] ?? null,
            ]);

            // Always 200 to Telegram so it does not retry endlessly on business denials.
            return response()->json([
                'status' => $result['ok'] ? 'ok' : 'handled',
                'error' => $result['error'] ?? null,
            ]);
        }

        // Ignore plain messages / other update types for this webhook.
        return response()->json(['status' => 'ignored']);
    }

    private function passesSecretCheck(Request $request, string $tenantId): bool
    {
        $expected = trim((string) config('services.telegram.webhook_secret', ''));

        if ($expected === '') {
            return false;
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return hash_equals($expected, $provided);
    }
}
