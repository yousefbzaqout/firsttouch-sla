<?php

declare(strict_types=1);

namespace App\Adapters\Notifications\Contracts;

use App\Models\Lead;
use App\Models\User;

interface NotificationDriverInterface
{
    public function sendLeadAssignedAlert(Lead $lead): bool;

    public function sendSlaAlert(Lead $lead, string $message): bool;

    public function sendEscalationAlert(Lead $lead, string $message): bool;

    public function sendAccountStatusAlert(string $tenantId, string $message): bool;

    public function sendSlaHalfwayWarning(Lead $lead): bool;

    public function sendSlaReassignmentWarning(Lead $lead, ?User $previousAgent, ?User $newAgent): bool;
}
