<?php

declare(strict_types=1);

namespace App\Adapters\Notifications;

use App\Adapters\Notifications\Contracts\NotificationDriverInterface;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LogNotificationDriver implements NotificationDriverInterface
{
    public function sendLeadAssignedAlert(Lead $lead): bool
    {
        Log::info('Lead Assigned Alert', ['lead_id' => $lead->id]);

        return true;
    }

    public function sendSlaAlert(Lead $lead, string $message): bool
    {
        Log::info('SLA Alert', ['lead_id' => $lead->id, 'message' => $message]);

        return true;
    }

    public function sendEscalationAlert(Lead $lead, string $message): bool
    {
        Log::info('Escalation Alert', ['lead_id' => $lead->id, 'message' => $message]);

        return true;
    }

    public function sendAccountStatusAlert(string $tenantId, string $message): bool
    {
        Log::info('Account Status Alert', ['tenant_id' => $tenantId, 'message' => $message]);

        return true;
    }

    public function sendSlaHalfwayWarning(Lead $lead): bool
    {
        Log::info('SLA Halfway Warning', [
            'lead_id' => $lead->id,
            'assigned_user_id' => $lead->assigned_user_id,
        ]);

        return true;
    }

    public function sendSlaReassignmentWarning(Lead $lead, ?User $previousAgent, ?User $newAgent): bool
    {
        Log::info('SLA Reassignment Warning', [
            'lead_id' => $lead->id,
            'previous_user_id' => $previousAgent?->id,
            'new_user_id' => $newAgent?->id,
        ]);

        return true;
    }
}
