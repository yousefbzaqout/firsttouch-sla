<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public function __construct(
        private readonly TelegramBotResolver $resolver,
    ) {}

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null  $replyMarkup
     */
    public function sendToTenant(string $tenantId, string $htmlMessage, ?array $replyMarkup = null): bool
    {
        $destination = $this->resolver->resolveForTenant($tenantId);

        if (! $destination->canSend()) {
            return false;
        }

        return $this->send($destination, $htmlMessage, $replyMarkup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null  $replyMarkup
     */
    public function sendToUser(User $user, string $htmlMessage, ?array $replyMarkup = null): bool
    {
        $destination = $this->resolver->resolveForUser($user);

        if (! $destination->canSend()) {
            return false;
        }

        return $this->send($destination, $htmlMessage, $replyMarkup);
    }

    /**
     * Send to each owner/admin personal chat, or tenant ops chat as fallback.
     */
    public function sendToManagers(string $tenantId, string $htmlMessage): bool
    {
        $destinations = $this->resolver->resolveForManagers($tenantId);

        if ($destinations === []) {
            return false;
        }

        $anySent = false;

        foreach ($destinations as $destination) {
            if ($this->send($destination, $htmlMessage)) {
                $anySent = true;
            }
        }

        return $anySent;
    }

    /**
     * Prefer the assigned sales rep personal chat; fall back to tenant ops chat.
     *
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null  $replyMarkup
     */
    public function sendLeadAssigned(User $assignee, string $tenantId, string $htmlMessage, ?array $replyMarkup = null): bool
    {
        if ($this->sendToUser($assignee, $htmlMessage, $replyMarkup)) {
            return true;
        }

        return $this->sendToTenant($tenantId, $htmlMessage, $replyMarkup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null  $replyMarkup
     */
    public function send(ResolvedTelegramDestination $destination, string $htmlMessage, ?array $replyMarkup = null): bool
    {
        if (! $destination->canSend()) {
            return false;
        }

        try {
            $payload = [
                'chat_id' => $destination->chatId,
                'text' => $htmlMessage,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
            }

            $response = Http::timeout(8)
                ->asForm()
                ->post(
                    'https://api.telegram.org/bot'.$destination->botToken.'/sendMessage',
                    $payload,
                );

            if (! $response->successful()) {
                Log::warning('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'used_fallback_token' => $destination->usedFallbackToken,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::error('Telegram sendMessage exception', [
                'error' => $exception->getMessage(),
                'used_fallback_token' => $destination->usedFallbackToken,
            ]);

            return false;
        }
    }

    public function answerCallbackQuery(
        string $botToken,
        string $callbackQueryId,
        string $text,
        bool $showAlert = false,
    ): bool {
        try {
            $response = Http::timeout(8)
                ->asForm()
                ->post(
                    'https://api.telegram.org/bot'.$botToken.'/answerCallbackQuery',
                    [
                        'callback_query_id' => $callbackQueryId,
                        'text' => mb_substr($text, 0, 200),
                        'show_alert' => $showAlert,
                    ],
                );

            return $response->successful();
        } catch (\Throwable $exception) {
            Log::error('Telegram answerCallbackQuery exception', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function clearInlineKeyboard(string $botToken, string|int $chatId, int $messageId): bool
    {
        try {
            $response = Http::timeout(8)
                ->asForm()
                ->post(
                    'https://api.telegram.org/bot'.$botToken.'/editMessageReplyMarkup',
                    [
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR),
                    ],
                );

            return $response->successful() || $response->status() === 400;
        } catch (\Throwable $exception) {
            Log::warning('Telegram editMessageReplyMarkup exception', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build Telegram inline keyboard for AI quick replies.
     * callback_data format: qr:{leadId}:{index} (fits Telegram 64-byte limit).
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null
     */
    public function quickReplyKeyboard(Lead $lead): ?array
    {
        $replies = $lead->ai_quick_replies;

        if (! is_array($replies) || $replies === []) {
            return null;
        }

        $rows = [];

        foreach ($replies as $index => $reply) {
            $label = trim($reply['label']);

            if ($label === '') {
                $label = 'Reply '.($index + 1);
            }

            $callbackData = 'qr:'.$lead->id.':'.$index;

            if (strlen($callbackData) > 64) {
                Log::warning('Telegram callback_data exceeds 64 bytes; skipping button', [
                    'lead_id' => $lead->id,
                    'index' => $index,
                ]);

                continue;
            }

            $rows[] = [[
                'text' => mb_substr($label, 0, 64),
                'callback_data' => $callbackData,
            ]];
        }

        if ($rows === []) {
            return null;
        }

        return ['inline_keyboard' => $rows];
    }
}
