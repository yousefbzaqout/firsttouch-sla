<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Drivers;

use App\DTOs\LeadData;
use App\Enums\LeadSource;
use App\Services\Webhooks\Contracts\WebhookDriverInterface;
use Illuminate\Http\Request;

class GoogleAdsWebhookDriver implements WebhookDriverInterface
{
    public function validateSignature(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $googleKey = $request->input('google_key');

        if (! is_string($googleKey) || $googleKey === '') {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($request->getContent(), true);
            $googleKey = is_array($decoded) ? ($decoded['google_key'] ?? null) : null;
        }

        if (! is_string($googleKey) || $googleKey === '') {
            return false;
        }

        return hash_equals($secret, $googleKey);
    }

    public function extractLeadData(Request $request, string $tenantId): LeadData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $columns = $this->parseUserColumnData($payload['user_column_data'] ?? null);

        $name = $columns['FULL_NAME'] ?? $columns['FULL NAME'] ?? '';
        $phone = $columns['PHONE_NUMBER'] ?? $columns['PHONE NUMBER'] ?? $columns['PHONE'] ?? '';
        $email = $columns['EMAIL'] ?? $columns['EMAIL_ADDRESS'] ?? null;

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::Google,
            externalLeadId: (string) ($payload['lead_id'] ?? $payload['id'] ?? ''),
            name: $name,
            phone: $phone,
            email: is_string($email) && $email !== '' ? $email : null,
            campaignId: isset($payload['campaign_id']) ? (string) $payload['campaign_id'] : null,
            formId: isset($payload['form_id']) ? (string) $payload['form_id'] : null,
            rawPayload: $payload,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseUserColumnData(mixed $userColumnData): array
    {
        if (! is_array($userColumnData)) {
            return [];
        }

        $mapped = [];

        foreach ($userColumnData as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = $row['column_id'] ?? $row['column_name'] ?? null;
            $value = $row['string_value'] ?? $row['value'] ?? null;

            if (! is_string($key) || $key === '' || ! is_string($value)) {
                continue;
            }

            $mapped[strtoupper(trim($key))] = $value;
        }

        return $mapped;
    }
}
