<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Enums\LeadSource;
use App\Exceptions\MissingWebhookSecretException;
use App\Models\TenantSetting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class WebhookSimulatorService
{
    /**
     * Build a signed Meta/TikTok webhook payload and dispatch it in-process
     * to the real webhook routes (avoids Sail self-HTTP deadlocks on localhost).
     *
     * @param  array{name?: string, phone?: string, inquiry?: string}  $leadData
     */
    public function simulate(string $tenantId, string $source, array $leadData): bool
    {
        $leadSource = LeadSource::tryFrom(strtolower($source));

        if ($leadSource !== LeadSource::Meta && $leadSource !== LeadSource::TikTok) {
            throw new InvalidArgumentException("Unsupported webhook simulation source [{$source}].");
        }

        $secret = $this->resolveTenantSecret($tenantId, $leadSource);

        $name = trim((string) ($leadData['name'] ?? 'Test Lead'));
        $phone = trim((string) ($leadData['phone'] ?? '+1234567890'));
        $inquiry = trim((string) ($leadData['inquiry'] ?? ''));

        if ($leadSource === LeadSource::Meta) {
            $payload = $this->buildMetaPayload($name, $phone, $inquiry);
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $path = '/api/v1/webhooks/meta/'.$tenantId;
            $server = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $jsonPayload, $secret),
            ];
        } else {
            $payload = $this->buildTikTokPayload($name, $phone, $inquiry);
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $path = '/api/v1/webhooks/tiktok/'.$tenantId;
            $server = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TIKTOK_SIGNATURE' => hash_hmac('sha256', $jsonPayload, $secret),
            ];
        }

        $response = $this->dispatchInternally($path, $jsonPayload, $server);

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * @param  array<string, string>  $server
     */
    private function dispatchInternally(string $path, string $jsonPayload, array $server): Response
    {
        // Nested HttpKernel::handle() replaces the container "request". If we leave
        // that nested request (or call terminate) during a Livewire/Filament action,
        // session flash notifications are written against the wrong request and only
        // appear after a full page refresh.
        $originalRequest = app()->bound('request') ? request() : null;

        $request = Request::create(
            uri: $path,
            method: 'POST',
            parameters: [],
            cookies: [],
            files: [],
            server: $server,
            content: $jsonPayload,
        );

        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernel::class);

        try {
            return $kernel->handle($request);
        } finally {
            if ($originalRequest !== null) {
                app()->instance('request', $originalRequest);
            }

            // Intentionally skip $kernel->terminate() for nested dispatches so
            // terminable middleware cannot close/flush the parent Filament session.
        }
    }

    private function resolveTenantSecret(string $tenantId, LeadSource $source): string
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        $secret = $source === LeadSource::Meta
            ? $setting?->meta_webhook_secret
            : $setting?->tiktok_webhook_secret;

        if (! is_string($secret) || trim($secret) === '') {
            throw new MissingWebhookSecretException($source->value);
        }

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetaPayload(string $name, string $phone, string $inquiry): array
    {
        $leadgenId = 'sandbox-meta-'.Str::lower(Str::ulid()->toString());

        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'sandbox-page-id',
                'time' => (int) floor(microtime(true) * 1000),
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => $leadgenId,
                        'page_id' => 'sandbox-page-id',
                        'form_id' => 'sandbox-meta-form',
                        'created_time' => time(),
                        'leadgen_data' => [
                            'leadgen_id' => $leadgenId,
                            'full_name' => $name,
                            'phone_number' => $phone,
                            'email' => null,
                            'campaign_id' => 'sandbox-meta-campaign',
                            'form_id' => 'sandbox-meta-form',
                            'inquiry' => $inquiry,
                            'field_data' => [
                                ['name' => 'full_name', 'values' => [$name]],
                                ['name' => 'phone_number', 'values' => [$phone]],
                                ['name' => 'inquiry', 'values' => [$inquiry]],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTikTokPayload(string $name, string $phone, string $inquiry): array
    {
        $leadId = 'sandbox-tt-'.Str::lower(Str::ulid()->toString());

        return [
            'event' => 'lead.create',
            'data' => [
                'lead_id' => $leadId,
                'name' => $name,
                'phone' => $phone,
                'email' => null,
                'campaign_id' => 'sandbox-tt-campaign',
                'form_id' => 'sandbox-tt-form',
                'inquiry' => $inquiry,
                'field_data' => [
                    ['name' => 'name', 'values' => [$name]],
                    ['name' => 'phone', 'values' => [$phone]],
                    ['name' => 'inquiry', 'values' => [$inquiry]],
                ],
            ],
        ];
    }
}
