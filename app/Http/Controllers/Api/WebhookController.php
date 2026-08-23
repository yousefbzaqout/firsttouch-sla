<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Adapters\Webhooks\WebhookAdapterFactory;
use App\Enums\LeadSource;
use App\Jobs\ProcessLeadIngestionJob;
use App\Models\Tenant;
use App\Services\Webhooks\Drivers\UniversalWebhookDriver;
use App\Services\Webhooks\Drivers\WebsiteFormWebhookDriver;
use App\Services\Webhooks\WebhookSecretResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookAdapterFactory $adapterFactory,
        WebhookSecretResolver $secretResolver,
    ): JsonResponse {
        // Resolve by route parameter name (not signature order) so named routes like
        // /webhooks/meta/{tenant_id} with defaults('source', 'meta') work correctly.
        $source = (string) $request->route('source', '');
        $tenantId = (string) $request->route('tenant_id', '');

        $leadSource = LeadSource::tryFrom($source);

        if (! $leadSource) {
            return response()->json(['error' => 'invalid_source'], 400);
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['error' => 'tenant_not_found'], 404);
        }

        $adapter = $adapterFactory->resolve($leadSource);
        $secret = $secretResolver->resolve($leadSource, $tenantId);

        if (! $adapter->validateSignature($request, $secret)) {
            if ($leadSource === LeadSource::Website) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid API Key',
                ], 401);
            }

            return response()->json(['error' => 'unauthorized'], 401);
        }

        if ($adapter instanceof UniversalWebhookDriver && $adapter->isHandshake($request)) {
            return response()->json(['status' => 'connected'], 200);
        }

        if ($adapter instanceof WebsiteFormWebhookDriver) {
            try {
                $adapter->validateInbound($request);
            } catch (ValidationException $exception) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $exception->errors(),
                ], 422);
            }

            $payload = $adapter->normalizePayload($request);
            $extractionRequest = Request::create(
                $request->url(),
                'POST',
                $payload,
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
            $leadData = $adapter->extractLeadData($extractionRequest, $tenantId);
            $lockKey = 'lead_lock:'.$leadData->externalLeadId;

            if (! Cache::lock($lockKey, 300)->get()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Lead already received',
                    'source' => 'website',
                ], 200);
            }

            /** @var array<string, string> $headers */
            $headers = [];
            foreach ($request->headers->all() as $name => $values) {
                $headers[$name] = $values[0] ?? '';
            }

            ProcessLeadIngestionJob::dispatch(
                $tenantId,
                $leadSource,
                $payload,
                $headers,
            );

            return response()->json([
                'success' => true,
                'message' => 'Lead received successfully',
                'source' => 'website',
            ], 201);
        }

        $raw = $request->getContent();
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) && $decoded !== [] ? $decoded : $request->all();

        $extractionRequest = Request::create($request->url(), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], is_array($decoded) ? $raw : json_encode($payload, JSON_THROW_ON_ERROR));

        $leadData = $adapter->extractLeadData($extractionRequest, $tenantId);
        $lockKey = 'lead_lock:'.$leadData->externalLeadId;

        if (! Cache::lock($lockKey, 300)->get()) {
            return response()->json(['status' => 'already_processed'], 200);
        }

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        ProcessLeadIngestionJob::dispatch(
            $tenantId,
            $leadSource,
            $payload,
            $headers,
        );

        return response()->json(['status' => 'accepted'], 200);
    }
}
