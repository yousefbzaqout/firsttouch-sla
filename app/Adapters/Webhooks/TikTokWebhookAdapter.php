<?php

declare(strict_types=1);

namespace App\Adapters\Webhooks;

use App\Adapters\Webhooks\Contracts\WebhookAdapterInterface;
use App\DTOs\LeadData;
use App\Enums\LeadSource;
use Illuminate\Http\Request;

class TikTokWebhookAdapter implements WebhookAdapterInterface
{
    public function validateSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = $request->header('X-TikTok-Signature', '');
        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function extractLeadData(Request $request, string $tenantId): LeadData
    {
        $payload = $request->all();
        $leadData = $payload['data'] ?? $payload;

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::TikTok,
            externalLeadId: (string) ($leadData['lead_id'] ?? $leadData['id'] ?? ''),
            name: (string) ($leadData['name'] ?? ''),
            phone: (string) ($leadData['phone'] ?? ''),
            email: $leadData['email'] ?? null,
            campaignId: $leadData['campaign_id'] ?? null,
            formId: $leadData['form_id'] ?? null,
            rawPayload: $payload,
        );
    }
}
