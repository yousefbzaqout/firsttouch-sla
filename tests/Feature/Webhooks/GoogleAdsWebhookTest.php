<?php

declare(strict_types=1);

use App\Jobs\ProcessLeadIngestionJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Bus::fake([ProcessLeadIngestionJob::class]);
});

/**
 * @return array<string, mixed>
 */
function googleAdsPayload(string $googleKey, string $leadId): array
{
    return [
        'lead_id' => $leadId,
        'campaign_id' => 'gads-campaign-1',
        'form_id' => 'gads-form-1',
        'google_key' => $googleKey,
        'user_column_data' => [
            [
                'column_id' => 'FULL_NAME',
                'string_value' => 'Google Lead',
            ],
            [
                'column_id' => 'PHONE_NUMBER',
                'string_value' => '+966500000100',
            ],
            [
                'column_id' => 'EMAIL',
                'string_value' => 'google.lead@example.com',
            ],
        ],
    ];
}

it('accepts a Google Ads webhook with a valid tenant google_key', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-google-key-'.Str::lower(Str::random(8));

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'google_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.google_secret' => 'global-google-secret-should-not-win']);

    $leadId = 'gads-lead-'.Str::lower(Str::random(6));
    $payload = json_encode(googleAdsPayload($tenantSecret, $leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/google/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('falls back to the global .env Google secret when the tenant secret is null', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'google_webhook_secret' => null,
    ]);

    $globalSecret = 'global-google-secret-'.Str::lower(Str::random(8));
    config(['services.webhooks.google_secret' => $globalSecret]);

    $leadId = 'gads-fallback-'.Str::lower(Str::random(6));
    $payload = json_encode(googleAdsPayload($globalSecret, $leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/google/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('returns 401 Unauthorized for an invalid google_key', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-only-google-key';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'google_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.google_secret' => 'global-google-secret']);

    $leadId = 'gads-bad-'.Str::lower(Str::random(6));
    $payload = json_encode(googleAdsPayload('wrong-google-key', $leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/google/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );

    $response->assertUnauthorized();
    $response->assertJson(['error' => 'unauthorized']);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('dispatches ProcessLeadIngestionJob on successful Google Ads webhook processing', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'dispatch-google-key';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'google_webhook_secret' => $tenantSecret,
    ]);

    $leadId = 'gads-dispatch-'.Str::lower(Str::random(6));
    $decoded = googleAdsPayload($tenantSecret, $leadId);
    $payload = json_encode($decoded, JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/google/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );

    $response->assertOk();

    Bus::assertDispatched(ProcessLeadIngestionJob::class, function (ProcessLeadIngestionJob $job) use ($tenant, $leadId): bool {
        $reflection = new ReflectionClass($job);
        $tenantProp = $reflection->getProperty('tenantId');
        $tenantProp->setAccessible(true);
        $sourceProp = $reflection->getProperty('source');
        $sourceProp->setAccessible(true);
        $payloadProp = $reflection->getProperty('payload');
        $payloadProp->setAccessible(true);

        /** @var array<string, mixed> $payload */
        $payload = $payloadProp->getValue($job);

        return $tenantProp->getValue($job) === $tenant->id
            && $sourceProp->getValue($job)->value === 'google'
            && ($payload['lead_id'] ?? null) === $leadId
            && ($payload['user_column_data'][0]['string_value'] ?? null) === 'Google Lead';
    });
});
