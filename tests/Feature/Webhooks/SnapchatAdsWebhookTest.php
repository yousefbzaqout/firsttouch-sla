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
function snapchatAdsPayload(string $leadId, ?string $snapchatKey = null): array
{
    $payload = [
        'lead_id' => $leadId,
        'campaign_id' => 'snap-campaign-1',
        'form_id' => 'snap-form-1',
        'full_name' => 'Snapchat Lead',
        'phone_number' => '+966500000200',
        'email' => 'snap.lead@example.com',
        'field_inputs' => [
            ['name' => 'FULL_NAME', 'value' => 'Snapchat Lead'],
            ['name' => 'PHONE_NUMBER', 'value' => '+966500000200'],
            ['name' => 'EMAIL', 'value' => 'snap.lead@example.com'],
        ],
    ];

    if ($snapchatKey !== null) {
        $payload['snapchat_key'] = $snapchatKey;
    }

    return $payload;
}

it('accepts a Snapchat Ads webhook with a valid tenant secret', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-snap-secret-'.Str::lower(Str::random(8));

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'snapchat_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.snapchat_secret' => 'global-snap-secret-should-not-win']);

    $leadId = 'snap-lead-'.Str::lower(Str::random(6));
    $payload = json_encode(snapchatAdsPayload($leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/snapchat/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SNAPCHAT_SIGNATURE' => $tenantSecret,
        ],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('falls back to the global .env Snapchat secret when the tenant secret is null', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'snapchat_webhook_secret' => null,
    ]);

    $globalSecret = 'global-snap-secret-'.Str::lower(Str::random(8));
    config(['services.webhooks.snapchat_secret' => $globalSecret]);

    $leadId = 'snap-fallback-'.Str::lower(Str::random(6));
    $payload = json_encode(snapchatAdsPayload($leadId, $globalSecret), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/snapchat/{$tenant->id}",
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

it('returns 401 Unauthorized for an invalid or missing Snapchat secret', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-only-snap-secret';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'snapchat_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.snapchat_secret' => 'global-snap-secret']);

    $leadId = 'snap-bad-'.Str::lower(Str::random(6));
    $payload = json_encode(snapchatAdsPayload($leadId, 'wrong-snap-key'), JSON_THROW_ON_ERROR);

    $invalid = $this->call(
        'POST',
        "/api/v1/webhooks/snapchat/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        $payload,
    );

    $invalid->assertUnauthorized();
    $invalid->assertJson(['error' => 'unauthorized']);

    $missing = $this->call(
        'POST',
        "/api/v1/webhooks/snapchat/{$tenant->id}",
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(snapchatAdsPayload($leadId.'-missing'), JSON_THROW_ON_ERROR),
    );

    $missing->assertUnauthorized();
    $missing->assertJson(['error' => 'unauthorized']);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('dispatches ProcessLeadIngestionJob on successful Snapchat Ads webhook processing', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'dispatch-snap-secret';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'snapchat_webhook_secret' => $tenantSecret,
    ]);

    $leadId = 'snap-dispatch-'.Str::lower(Str::random(6));
    $decoded = snapchatAdsPayload($leadId);
    $payload = json_encode($decoded, JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/snapchat/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SNAPCHAT_SIGNATURE' => $tenantSecret,
        ],
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
            && $sourceProp->getValue($job)->value === 'snapchat'
            && ($payload['lead_id'] ?? null) === $leadId
            && ($payload['full_name'] ?? null) === 'Snapchat Lead';
    });
});
