<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use Illuminate\Support\Facades\Log;

class TelegramQuickReplyCallbackHandler
{
    public function __construct(
        private readonly TelegramBotResolver $resolver,
        private readonly TelegramNotifier $notifier,
        private readonly TelegramMessageBuilder $messageBuilder,
        private readonly LeadWorkflowService $workflow,
    ) {}

    /**
     * @param  array<string, mixed>  $callbackQuery
     * @return array{ok: bool, error?: string}
     */
    public function handle(string $tenantId, array $callbackQuery): array
    {
        $callbackId = isset($callbackQuery['id']) ? (string) $callbackQuery['id'] : '';
        $data = isset($callbackQuery['data']) ? (string) $callbackQuery['data'] : '';
        $from = is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [];
        $fromChatId = isset($from['id']) ? (string) $from['id'] : '';
        $message = is_array($callbackQuery['message'] ?? null) ? $callbackQuery['message'] : [];
        $messageChatId = isset($message['chat']['id']) ? $message['chat']['id'] : null;
        $messageId = isset($message['message_id']) ? (int) $message['message_id'] : 0;

        $destination = $this->resolver->resolveForTenant($tenantId);
        $botToken = $destination->botToken;

        if ($botToken === '' || $callbackId === '') {
            return ['ok' => false, 'error' => 'telegram_not_configured'];
        }

        $parsed = $this->parseCallbackData($data);

        if ($parsed === null) {
            $this->notifier->answerCallbackQuery($botToken, $callbackId, 'Unknown action.', true);

            return ['ok' => false, 'error' => 'invalid_callback_data'];
        }

        [$leadId, $replyIndex] = $parsed;

        $lead = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->where('tenant_id', $tenantId)
            ->whereKey($leadId)
            ->first();

        if ($lead === null) {
            $this->notifier->answerCallbackQuery($botToken, $callbackId, 'Lead not found.', true);

            return ['ok' => false, 'error' => 'lead_not_found'];
        }

        $agent = User::query()
            ->where('tenant_id', $tenantId)
            ->where('telegram_chat_id', $fromChatId)
            ->where('is_active', true)
            ->first();

        if ($agent === null) {
            $this->notifier->answerCallbackQuery(
                $botToken,
                $callbackId,
                'Your Telegram is not linked to a FirstTouch user.',
                true,
            );

            return ['ok' => false, 'error' => 'agent_not_linked'];
        }

        if ($lead->assigned_user_id === null || (int) $lead->assigned_user_id !== (int) $agent->id) {
            $this->notifier->answerCallbackQuery(
                $botToken,
                $callbackId,
                'This lead is assigned to another agent.',
                true,
            );

            Log::warning('Telegram quick-reply denied: lead not assigned to callback user', [
                'lead_id' => $lead->id,
                'assigned_user_id' => $lead->assigned_user_id,
                'callback_user_id' => $agent->id,
            ]);

            return ['ok' => false, 'error' => 'forbidden'];
        }

        $replies = $lead->ai_quick_replies ?? [];
        $reply = $replies[$replyIndex] ?? null;

        if ($reply === null) {
            $this->notifier->answerCallbackQuery($botToken, $callbackId, 'Reply template missing.', true);

            return ['ok' => false, 'error' => 'reply_missing'];
        }

        $label = trim($reply['label']) !== '' ? trim($reply['label']) : 'Quick Reply';
        $text = trim($reply['text']);

        if ($text === '') {
            $this->notifier->answerCallbackQuery($botToken, $callbackId, 'Reply text is empty.', true);

            return ['ok' => false, 'error' => 'reply_empty'];
        }

        try {
            if ($lead->status->isAwaitingAgentAction()) {
                $this->workflow->markInProgress($lead, $agent);
            } elseif ($lead->status === LeadStatus::InProgress && $lead->first_action_at === null) {
                $this->workflow->markInProgress($lead, $agent);
            }

            $lead->refresh();

            /** @var array<string, mixed> $meta */
            $meta = $lead->meta_data ?? [];
            $meta['telegram_quick_reply_used'] = [
                'index' => $replyIndex,
                'label' => $label,
                'used_at' => now()->toIso8601String(),
                'user_id' => $agent->id,
            ];
            $lead->update(['meta_data' => $meta]);
        } catch (\Throwable $exception) {
            Log::error('Telegram quick-reply status update failed', [
                'lead_id' => $lead->id,
                'error' => $exception->getMessage(),
            ]);
            $this->notifier->answerCallbackQuery($botToken, $callbackId, 'Could not update lead status.', true);

            return ['ok' => false, 'error' => 'status_update_failed'];
        }

        $copyable = $this->messageBuilder->quickReplyCopyable($lead, $label, $text);
        $sent = $this->notifier->sendToUser($agent, $copyable);

        if ($messageChatId !== null && $messageId > 0) {
            $this->notifier->clearInlineKeyboard($botToken, $messageChatId, $messageId);
        }

        $this->notifier->answerCallbackQuery(
            $botToken,
            $callbackId,
            $sent ? 'Reply ready — copy the message below.' : 'Lead updated, but message delivery failed.',
            ! $sent,
        );

        if ($sent) {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => 'delivery_failed'];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function parseCallbackData(string $data): ?array
    {
        if (! str_starts_with($data, 'qr:')) {
            return null;
        }

        $parts = explode(':', $data);

        // qr:{uuid}:{index} — UUID contains no colons when using standard format.
        if (count($parts) !== 3) {
            return null;
        }

        $leadId = $parts[1];
        $index = filter_var($parts[2], FILTER_VALIDATE_INT);

        if ($leadId === '' || $index === false || $index < 0) {
            return null;
        }

        return [$leadId, $index];
    }
}
