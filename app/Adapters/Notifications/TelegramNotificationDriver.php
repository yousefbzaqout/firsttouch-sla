<?php

declare(strict_types=1);

namespace App\Adapters\Notifications;

use App\Adapters\Notifications\Contracts\NotificationDriverInterface;
use App\Models\Lead;
use App\Models\User;
use App\Services\Telegram\TelegramMessageBuilder;
use App\Services\Telegram\TelegramNotifier;

class TelegramNotificationDriver implements NotificationDriverInterface
{
    public function __construct(
        private readonly TelegramNotifier $notifier,
        private readonly TelegramMessageBuilder $messageBuilder,
    ) {}

    public function sendLeadAssignedAlert(Lead $lead): bool
    {
        $message = $this->messageBuilder->leadAssigned($lead);
        $assignee = $lead->assignedUser;
        $replyMarkup = $this->notifier->quickReplyKeyboard($lead);

        if ($assignee !== null) {
            return $this->notifier->sendLeadAssigned($assignee, $lead->tenant_id, $message, $replyMarkup);
        }

        return $this->notifier->sendToTenant($lead->tenant_id, $message, $replyMarkup);
    }

    public function sendSlaAlert(Lead $lead, string $message): bool
    {
        return $this->notifier->sendToManagers(
            $lead->tenant_id,
            $this->messageBuilder->systemAlert($lead, $message),
        );
    }

    public function sendEscalationAlert(Lead $lead, string $message): bool
    {
        return $this->notifier->sendToManagers(
            $lead->tenant_id,
            $this->messageBuilder->slaBreach($lead, $message),
        );
    }

    public function sendAccountStatusAlert(string $tenantId, string $message): bool
    {
        return $this->notifier->sendToManagers(
            $tenantId,
            $this->messageBuilder->accountStatus($message),
        );
    }

    public function sendSlaHalfwayWarning(Lead $lead): bool
    {
        $message = $this->messageBuilder->slaHalfwayWarning($lead);
        $assignee = $lead->assignedUser;

        if ($assignee instanceof User) {
            return $this->notifier->sendLeadAssigned($assignee, $lead->tenant_id, $message);
        }

        return $this->notifier->sendToTenant($lead->tenant_id, $message);
    }

    public function sendSlaReassignmentWarning(Lead $lead, ?User $previousAgent, ?User $newAgent): bool
    {
        $message = $this->messageBuilder->slaReassignmentWarning($lead, $previousAgent, $newAgent);
        $anySent = false;

        if ($previousAgent instanceof User) {
            $anySent = $this->notifier->sendToUser($previousAgent, $message);
        }

        if ($newAgent instanceof User && ($previousAgent === null || $newAgent->id !== $previousAgent->id)) {
            $anySent = $this->notifier->sendToUser($newAgent, $message) || $anySent;
        }

        $anySent = $this->notifier->sendToManagers($lead->tenant_id, $message) || $anySent;

        return $anySent;
    }
}
