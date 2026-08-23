<?php

declare(strict_types=1);

namespace App\Adapters\Webhooks;

use App\Adapters\Webhooks\Contracts\WebhookAdapterInterface;
use App\DTOs\LeadData;
use App\Enums\LeadSource;
use Illuminate\Http\Request;

class MetaWebhookAdapter implements WebhookAdapterInterface
{
    public function validateSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $payload = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function extractLeadData(Request $request, string $tenantId): LeadData
    {
        $payload = $request->all();
        $leadData = $payload['entry'][0]['changes'][0]['value']['leadgen_data'] ?? $payload;

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::Meta,
            externalLeadId: (string) ($leadData['leadgen_id'] ?? $leadData['id'] ?? $request->input('id', '')),
            name: (string) ($leadData['full_name'] ?? $leadData['name'] ?? ''),
            phone: (string) ($leadData['phone_number'] ?? $leadData['phone'] ?? ''),
            email: $leadData['email'] ?? null,
            campaignId: $leadData['campaign_id'] ?? null,
            formId: $leadData['form_id'] ?? null,
            rawPayload: $payload,
        );
    }
}
