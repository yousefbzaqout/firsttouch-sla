<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Drivers;

use App\DTOs\LeadData;
use App\Enums\LeadSource;
use App\Services\Webhooks\Contracts\WebhookDriverInterface;
use Illuminate\Http\Request;

class SnapchatAdsWebhookDriver implements WebhookDriverInterface
{
    public function validateSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $provided = $this->extractProvidedKey($request);

        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
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

        /** @var array<string, mixed> $lead */
        $lead = is_array($payload['lead'] ?? null) ? $payload['lead'] : $payload;
        $fields = $this->parseFieldInputs(
            $lead['field_inputs'] ?? $lead['fields'] ?? $payload['field_inputs'] ?? $payload['fields'] ?? null,
        );

        $name = $this->resolveName($lead, $fields);
        $phone = $this->firstNonEmptyString(
            $lead['phone_number'] ?? null,
            $lead['phone'] ?? null,
            $fields['PHONE_NUMBER'] ?? null,
            $fields['PHONE'] ?? null,
        );
        $email = $this->firstNonEmptyString(
            $lead['email'] ?? null,
            $fields['EMAIL'] ?? null,
            $fields['EMAIL_ADDRESS'] ?? null,
        );

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::Snapchat,
            externalLeadId: (string) ($lead['lead_id'] ?? $lead['id'] ?? $payload['lead_id'] ?? $payload['id'] ?? ''),
            name: $name,
            phone: $phone,
            email: $email !== '' ? $email : null,
            campaignId: isset($lead['campaign_id'])
                ? (string) $lead['campaign_id']
                : (isset($payload['campaign_id']) ? (string) $payload['campaign_id'] : null),
            formId: isset($lead['form_id'])
                ? (string) $lead['form_id']
                : (isset($payload['form_id']) ? (string) $payload['form_id'] : null),
            rawPayload: $payload,
        );
    }

    private function extractProvidedKey(Request $request): ?string
    {
        $header = $request->header('X-Snapchat-Signature');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $queryKey = $request->query('snapchat_key');
        if (is_string($queryKey) && $queryKey !== '') {
            return $queryKey;
        }

        $inputKey = $request->input('snapchat_key');
        if (is_string($inputKey) && $inputKey !== '') {
            return $inputKey;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded)) {
            $payloadKey = $decoded['snapchat_key'] ?? null;
            if (is_string($payloadKey) && $payloadKey !== '') {
                return $payloadKey;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @param  array<string, string>  $fields
     */
    private function resolveName(array $lead, array $fields): string
    {
        $fullName = $this->firstNonEmptyString(
            $lead['full_name'] ?? null,
            $lead['name'] ?? null,
            $fields['FULL_NAME'] ?? null,
            $fields['NAME'] ?? null,
        );

        if ($fullName !== '') {
            return $fullName;
        }

        $first = $this->firstNonEmptyString(
            $lead['first_name'] ?? null,
            $fields['FIRST_NAME'] ?? null,
        );
        $last = $this->firstNonEmptyString(
            $lead['last_name'] ?? null,
            $fields['LAST_NAME'] ?? null,
        );

        return trim($first.' '.$last);
    }

    /**
     * @return array<string, string>
     */
    private function parseFieldInputs(mixed $fieldInputs): array
    {
        if (! is_array($fieldInputs)) {
            return [];
        }

        $mapped = [];

        foreach ($fieldInputs as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['name'] ?? $row['field_name'] ?? $row['column_id'] ?? $row['key'] ?? null;
            $value = $row['value'] ?? $row['string_value'] ?? $row['field_value'] ?? null;

            if (! is_string($key) || $key === '' || ! is_string($value)) {
                continue;
            }

            $mapped[strtoupper(trim($key))] = $value;
        }

        return $mapped;
    }

    private function firstNonEmptyString(mixed ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
