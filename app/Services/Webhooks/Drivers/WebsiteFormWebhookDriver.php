<?php

declare(strict_types=1);

namespace App\Services\Webhooks\Drivers;

use App\DTOs\LeadData;
use App\Enums\LeadSource;
use App\Services\Webhooks\Contracts\WebhookDriverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WebsiteFormWebhookDriver implements WebhookDriverInterface
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

    /**
     * @throws ValidationException
     */
    public function validateInbound(Request $request): void
    {
        $data = $this->resolveInput($request);

        Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'inquiry' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:2048'],
        ])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizePayload(Request $request): array
    {
        $data = $this->resolveInput($request);

        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $email = isset($data['email']) && is_string($data['email']) && trim($data['email']) !== ''
            ? trim($data['email'])
            : null;
        $inquiry = $this->firstNonEmptyString($data['inquiry'] ?? null, $data['notes'] ?? null);
        $pageUrl = isset($data['page_url']) && is_string($data['page_url']) && trim($data['page_url']) !== ''
            ? trim($data['page_url'])
            : null;

        $externalLeadId = $this->firstNonEmptyString(
            $data['lead_id'] ?? null,
            $data['id'] ?? null,
            $data['external_id'] ?? null,
        );

        if ($externalLeadId === '') {
            $externalLeadId = 'website-'.hash(
                'sha256',
                $name.'|'.$phone.'|'.($email ?? '').'|'.($pageUrl ?? '').'|'.$inquiry,
            );
        }

        return [
            'lead_id' => $externalLeadId,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'inquiry' => $inquiry !== '' ? $inquiry : null,
            'notes' => isset($data['notes']) && is_string($data['notes']) ? trim($data['notes']) : null,
            'page_url' => $pageUrl,
        ];
    }

    public function extractLeadData(Request $request, string $tenantId): LeadData
    {
        $payload = $this->normalizePayload($request);

        return new LeadData(
            tenantId: $tenantId,
            source: LeadSource::Website,
            externalLeadId: (string) $payload['lead_id'],
            name: (string) $payload['name'],
            phone: (string) $payload['phone'],
            email: is_string($payload['email'] ?? null) ? $payload['email'] : null,
            campaignId: null,
            formId: is_string($payload['page_url'] ?? null) ? $payload['page_url'] : null,
            rawPayload: $payload,
        );
    }

    private function extractProvidedKey(Request $request): ?string
    {
        $websiteHeader = $request->header('X-Website-Api-Key');
        if (is_string($websiteHeader) && $websiteHeader !== '') {
            return $websiteHeader;
        }

        $apiKeyHeader = $request->header('X-Api-Key');
        if (is_string($apiKeyHeader) && $apiKeyHeader !== '') {
            return $apiKeyHeader;
        }

        $authorization = $request->header('Authorization');
        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            $bearer = trim(substr($authorization, 7));
            if ($bearer !== '') {
                return $bearer;
            }
        }

        // Query-string API keys are rejected (access-log / Referer leakage risk).
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInput(Request $request): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($request->getContent(), true);

        if (is_array($decoded) && $decoded !== []) {
            return $decoded;
        }

        return $request->all();
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
