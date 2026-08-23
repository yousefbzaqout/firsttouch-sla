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
function universalZapierPayload(string $leadId): array
{
    return [
        'lead_id' => $leadId,
        'full_name' => 'Zapier Lead',
        'phone_number' => '+966500000300',
        'email_address' => 'zapier.lead@example.com',
        'campaign_id' => 'zap-campaign-1',
        'form_id' => 'make-form-1',
    ];
}

it('accepts a Zapier/Make lead payload with a valid tenant secret', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-universal-'.Str::lower(Str::random(8));

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'universal_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.universal_secret' => 'global-universal-should-not-win']);

    $leadId = 'univ-lead-'.Str::lower(Str::random(6));
    $payload = json_encode(universalZapierPayload($leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UNIVERSAL_SECRET' => $tenantSecret,
        ],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('returns connected for ping/test handshakes without dispatching ingestion', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'handshake-universal-secret';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'universal_webhook_secret' => $tenantSecret,
    ]);

    $ping = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZAPIER_SECRET' => $tenantSecret,
        ],
        json_encode(['event' => 'ping'], JSON_THROW_ON_ERROR),
    );

    $ping->assertOk();
    $ping->assertJson(['status' => 'connected']);

    $test = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$tenantSecret,
        ],
        json_encode(['test' => true], JSON_THROW_ON_ERROR),
    );

    $test->assertOk();
    $test->assertJson(['status' => 'connected']);

    $empty = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UNIVERSAL_SECRET' => $tenantSecret,
        ],
        '{}',
    );

    $empty->assertOk();
    $empty->assertJson(['status' => 'connected']);

    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('returns 401 Unauthorized when the universal secret is incorrect or missing', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    $tenantSecret = 'tenant-only-universal';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'universal_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.universal_secret' => 'global-universal']);

    $leadId = 'univ-bad-'.Str::lower(Str::random(6));
    $payload = json_encode(universalZapierPayload($leadId), JSON_THROW_ON_ERROR);

    $invalid = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UNIVERSAL_SECRET' => 'wrong-secret',
        ],
        $payload,
    );

    $invalid->assertUnauthorized();
    $invalid->assertJson(['error' => 'unauthorized']);

    $missing = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $payload,
    );

    $missing->assertUnauthorized();
    $missing->assertJson(['error' => 'unauthorized']);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('falls back to the global .env universal secret when the tenant secret is null', function (): void {
    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'universal_webhook_secret' => null,
    ]);

    $globalSecret = 'global-universal-'.Str::lower(Str::random(8));
    config(['services.webhooks.universal_secret' => $globalSecret]);

    $leadId = 'univ-fallback-'.Str::lower(Str::random(6));
    $payload = json_encode(universalZapierPayload($leadId), JSON_THROW_ON_ERROR);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/universal/{$tenant->id}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$globalSecret,
        ],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    Bus::assertDispatched(ProcessLeadIngestionJob::class, function (ProcessLeadIngestionJob $job) use ($tenant, $leadId): bool {
        $reflection = new ReflectionClass($job);
        $tenantProp = $reflection->getProperty('tenantId');
        $tenantProp->setAccessible(true);
        $sourceProp = $reflection->getProperty('source');
        $sourceProp->setAccessible(true);
        $payloadProp = $reflection->getProperty('payload');
        $payloadProp->setAccessible(true);

        /** @var array<string, mixed> $jobPayload */
        $jobPayload = $payloadProp->getValue($job);

        return $tenantProp->getValue($job) === $tenant->id
            && $sourceProp->getValue($job)->value === 'universal'
            && ($jobPayload['lead_id'] ?? null) === $leadId
            && ($jobPayload['full_name'] ?? null) === 'Zapier Lead';
    });
});
