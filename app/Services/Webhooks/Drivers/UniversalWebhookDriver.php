<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Drivers;

use App\DTOs\LeadData;
use App\Enums\LeadSource;
use App\Services\Webhooks\Contracts\WebhookDriverInterface;
use Illuminate\Http\Request;

class UniversalWebhookDriver implements WebhookDriverInterface
{
    public function validateSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = $this->extractProvidedSecret($request);

        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }

    /**
     * Zapier/Make/n8n handshake or connectivity probe — do not ingest a lead.
     */
    public function isHandshake(Request $request): bool
    {
        $raw = trim($request->getContent());

        if ($raw === '' || $raw === '{}' || $raw === '[]' || $raw === 'null') {
            return true;
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            $payload = $request->all();
        }

        if ($payload === []) {
            return true;
        }

        if (($payload['event'] ?? null) === 'ping') {
            return true;
        }

        if (($payload['test'] ?? null) === true || ($payload['test'] ?? null) === 'true' || ($payload['test'] ?? null) === 1) {
            return true;
        }

        if (($payload['zap'] ?? null) === 'subscribe' || ($payload['type'] ?? null) === 'subscribe') {
            return true;
        }

        return false;
    }

    public function extractLeadData(Request $request, string $tenantId): LeadData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        if ($payload === []) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $name = $this->resolveName($data);
        $phone = $this->firstNonEmptyString(
            $data['phone'] ?? null,
            $data['phone_number'] ?? null,
            $data['mobile'] ?? null,
            $data['mobile_number'] ?? null,
            $data['Phone'] ?? null,
            $data['Phone Number'] ?? null,
        );
        $email = $this->firstNonEmptyString(
            $data['email'] ?? null,
            $data['email_address'] ?? null,
            $data['Email'] ?? null,
            $data['Email Address'] ?? null,
        );

        $externalLeadId = $this->firstNonEmptyString(
            $data['lead_id'] ?? null,
            $data['id'] ?? null,
            $data['external_id'] ?? null,
            $data['external_lead_id'] ?? null,
            $payload['lead_id'] ?? null,
            $payload['id'] ?? null,
        );

        if ($externalLeadId === '') {
            $externalLeadId = 'universal-'.hash('sha256', $request->getContent() !== '' ? $request->getContent() : json_encode($payload, JSON_THROW_ON_ERROR));
        }

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::Universal,
            externalLeadId: $externalLeadId,
            name: $name,
            phone: $phone,
            email: $email !== '' ? $email : null,
            campaignId: isset($data['campaign_id'])
                ? (string) $data['campaign_id']
                : (isset($payload['campaign_id']) ? (string) $payload['campaign_id'] : null),
            formId: isset($data['form_id'])
                ? (string) $data['form_id']
                : (isset($payload['form_id']) ? (string) $payload['form_id'] : null),
            rawPayload: $payload,
        );
    }

    private function extractProvidedSecret(Request $request): ?string
    {
        $universalHeader = $request->header('X-Universal-Secret');
        if (is_string($universalHeader) && $universalHeader !== '') {
            return $universalHeader;
        }

        $zapierHeader = $request->header('X-Zapier-Secret');
        if (is_string($zapierHeader) && $zapierHeader !== '') {
            return $zapierHeader;
        }

        $authorization = $request->header('Authorization');
        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $bearer = trim(substr($authorization, 7));
            if ($bearer !== '') {
                return $bearer;
            }
        }

        // Query-string secrets are rejected (access-log / Referer leakage risk).
        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveName(array $data): string
    {
        $fullName = $this->firstNonEmptyString(
            $data['full_name'] ?? null,
            $data['name'] ?? null,
            $data['Full Name'] ?? null,
            $data['Name'] ?? null,
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $first = $this->firstNonEmptyString(
            $data['first_name'] ?? null,
            $data['firstName'] ?? null,
            $data['First Name'] ?? null,
        );
        $last = $this->firstNonEmptyString(
            $data['last_name'] ?? null,
            $data['lastName'] ?? null,
            $data['Last Name'] ?? null,
        );

        return trim($first.' '.$last);
    }

    private function firstNonEmptyString(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }

            if (is_int($candidate) || is_float($candidate)) {
                return (string) $candidate;
            }
        }

        return '';
    }
}
