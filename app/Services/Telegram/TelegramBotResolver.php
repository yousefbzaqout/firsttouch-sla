<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\UserRole;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TelegramBotResolver
{
    public function resolveForTenant(string $tenantId): ResolvedTelegramDestination
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return $this->resolveFromSetting($setting, $tenantId);
    }

    public function resolveForUser(User $user): ResolvedTelegramDestination
    {
        $tenantId = $user->tenant_id;
        $setting = $tenantId !== null
            ? TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()
            : null;

        $base = $this->resolveBotToken($setting, $tenantId);
        $chatId = $this->normalizeChatId($user->telegram_chat_id);

        if ($chatId === null) {
            Log::warning('Telegram user chat ID missing; notification suppressed', [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
            ]);
        }

        return new ResolvedTelegramDestination(
            botToken: $base['botToken'],
            chatId: $chatId,
            usedFallbackToken: $base['usedFallback'],
        );
    }

    /**
     * Prefer personal chats for owners/admins; fall back to the tenant ops chat.
     *
     * @return list<ResolvedTelegramDestination>
     */
    public function resolveForManagers(string $tenantId): array
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $base = $this->resolveBotToken($setting, $tenantId);

        /** @var Collection<int, User> $managers */
        $managers = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Owner, UserRole::Admin, UserRole::SuperAdmin])
            ->whereNotNull('telegram_chat_id')
            ->get();

        $destinations = [];
        $seenChatIds = [];

        foreach ($managers as $manager) {
            $chatId = $this->normalizeChatId($manager->telegram_chat_id);

            if ($chatId === null || isset($seenChatIds[$chatId])) {
                continue;
            }

            $seenChatIds[$chatId] = true;
            $destinations[] = new ResolvedTelegramDestination(
                botToken: $base['botToken'],
                chatId: $chatId,
                usedFallbackToken: $base['usedFallback'],
            );
        }

        if ($destinations !== []) {
            return $destinations;
        }

        $tenantDestination = $this->resolveFromSetting($setting, $tenantId);

        return $tenantDestination->canSend() ? [$tenantDestination] : [];
    }

    public function resolveFromSetting(?TenantSetting $setting, ?string $tenantId = null): ResolvedTelegramDestination
    {
        $base = $this->resolveBotToken($setting, $tenantId);
        $chatId = is_string($setting?->telegram_chat_id)
            ? trim($setting->telegram_chat_id)
            : '';

        if ($chatId === '') {
            Log::warning('Telegram chat ID missing; notification suppressed', [
                'tenant_id' => $tenantId ?? $setting?->tenant_id,
            ]);

            return new ResolvedTelegramDestination(
                botToken: $base['botToken'],
                chatId: null,
                usedFallbackToken: $base['usedFallback'],
            );
        }

        if ($base['botToken'] === '') {
            Log::warning('Telegram bot token missing (tenant and global); notification suppressed', [
                'tenant_id' => $tenantId ?? $setting?->tenant_id,
            ]);
        }

        return new ResolvedTelegramDestination(
            botToken: $base['botToken'],
            chatId: $chatId,
            usedFallbackToken: $base['usedFallback'],
        );
    }

    /**
     * @return array{botToken: string, usedFallback: bool}
     */
    private function resolveBotToken(?TenantSetting $setting, ?string $tenantId): array
    {
        $tenantToken = is_string($setting?->telegram_bot_token)
            ? trim($setting->telegram_bot_token)
            : '';

        $fallbackToken = trim((string) config('services.telegram.bot_token', ''));
        $usedFallback = $tenantToken === '';

        return [
            'botToken' => $usedFallback ? $fallbackToken : $tenantToken,
            'usedFallback' => $usedFallback,
        ];
    }

    private function normalizeChatId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $chatId = trim((string) $value);

        return $chatId !== '' ? $chatId : null;
    }
}
