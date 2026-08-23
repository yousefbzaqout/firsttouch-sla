<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SlaStatus;
use App\Jobs\Concerns\ConfiguresReliableQueueJob;
use App\Models\Lead;
use App\Models\TenantSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchOutboundWebhookJob implements ShouldQueue
{
    use ConfiguresReliableQueueJob;
    use Queueable;

    public function __construct(
        private readonly string $leadId,
        private readonly string $event = 'lead.updated',
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $lead = Lead::withoutGlobalScopes()
            ->with('assignedUser')
            ->find($this->leadId);

        if ($lead === null) {
            return;
        }

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->first();

        $url = is_string($setting?->outbound_webhook_url)
            ? trim($setting->outbound_webhook_url)
            : '';

        if ($url === '') {
            return;
        }

        $assignee = $lead->assignedUser;

        $payload = [
            'event' => $this->event,
            'lead_id' => (string) $lead->id,
            'name' => $lead->name,
            'phone' => $lead->phone,
            'status' => $lead->status->value,
            'assigned_user' => $assignee !== null ? $assignee->name : null,
            'sla_breached' => $lead->sla_status === SlaStatus::Breached,
            'timestamp' => now()->toIso8601String(),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'FirstTouch-SLA-OutboundWebhook/1.0',
        ];

        $secret = is_string($setting?->outbound_webhook_secret)
            ? trim($setting->outbound_webhook_secret)
            : '';

        if ($secret !== '') {
            $headers['X-FirstTouch-Signature'] = hash_hmac('sha256', $jsonPayload, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->withBody($jsonPayload, 'application/json')
                ->post($url);

            if (! $response->successful()) {
                Log::warning('Outbound webhook delivery failed', [
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'event' => $this->event,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                throw new RuntimeException('Outbound webhook returned HTTP '.$response->status());
            }
        } catch (Throwable $exception) {
            Log::warning('Outbound webhook delivery exception', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'event' => $this->event,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
