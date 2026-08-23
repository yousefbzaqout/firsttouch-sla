<?php

declare(strict_types=1);

namespace App\Adapters\Notifications;

use App\Adapters\Notifications\Contracts\NotificationDriverInterface;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class N8nNotificationDriver implements NotificationDriverInterface
{
    public function __construct(
        private readonly string $webhookUrl,
    ) {}

    public function sendLeadAssignedAlert(Lead $lead): bool
    {
        return $this->send($lead, "Lead assigned: {$lead->name} ({$lead->phone})", 'lead_assigned');
    }

    public function sendSlaAlert(Lead $lead, string $message): bool
    {
        return $this->send($lead, $message, 'sla_alert');
    }

    public function sendEscalationAlert(Lead $lead, string $message): bool
    {
        return $this->send($lead, $message, 'escalation');
    }

    public function sendAccountStatusAlert(string $tenantId, string $message): bool
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $tenant = Tenant::find($tenantId);

        $normalized = strtolower($message);
        $eventType = str_contains($normalized, 'exhaust') || str_contains($normalized, 'zero')
            ? 'credit_exhausted'
            : (str_contains($normalized, 'low')
                ? 'credit_low'
                : 'account_status');

        $payload = [
            'event_type' => $eventType,
            'type' => 'account_status',
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant?->name,
            'message' => $message,
            'notification_driver' => $setting !== null ? $setting->notification_driver : 'telegram',
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(5)->post($this->webhookUrl, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('N8n account status notification failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);

            return false;
        }
    }

    public function sendSlaHalfwayWarning(Lead $lead): bool
    {
        return $this->send(
            $lead,
            "SLA 50% warning: {$lead->name} ({$lead->phone}) still pending action.",
            'sla_halfway_warning',
        );
    }

    public function sendSlaReassignmentWarning(Lead $lead, ?User $previousAgent, ?User $newAgent): bool
    {
        $previous = $previousAgent !== null ? $previousAgent->name : 'previous agent';
        $next = $newAgent !== null ? $newAgent->name : 'unassigned pool';

        return $this->send(
            $lead,
            "SLA 80% breach warning: lead {$lead->name} pulled from {$previous} and routed to {$next}.",
            'sla_reassignment_warning',
        );
    }

    private function send(Lead $lead, string $message, string $type): bool
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $tenant = Tenant::find($lead->tenant_id);

        $eventType = match ($type) {
            'escalation' => 'sla_breach',
            'lead_assigned' => 'lead_assigned',
            'sla_halfway_warning' => 'sla_warning',
            'sla_reassignment_warning' => 'sla_breach_warning',
            default => str_contains(strtolower($message), 'credit') ? 'credit_exhausted' : 'sla_warning',
        };

        $payload = [
            'event_type' => $eventType,
            'type' => $type,
            'tenant_id' => $lead->tenant_id,
            'tenant_name' => $tenant?->name,
            'lead_id' => $lead->id,
            'lead_name' => $lead->name,
            'lead_phone' => $lead->phone,
            'phone' => $lead->phone,
            'time_remaining' => $lead->sla_deadline !== null && $lead->sla_deadline->isFuture()
                ? $lead->sla_deadline->diffForHumans(now(), ['parts' => 2, 'short' => true]).' remaining'
                : null,
            'message' => $message,
            'notification_driver' => $setting !== null ? $setting->notification_driver : 'telegram',
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $response = Http::timeout(5)->post($this->webhookUrl, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('N8n notification failed', [
                'error' => $e->getMessage(),
                'lead_id' => $lead->id,
            ]);

            return false;
        }
    }
}
