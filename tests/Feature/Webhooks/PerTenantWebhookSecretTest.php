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
    Bus::fake();
});

it('accepts a Meta webhook signed with the tenant custom DB secret', function (): void {
    $tenant = Tenant::factory()->create();
    $tenantSecret = 'tenant-meta-secret-'.Str::lower(Str::random(8));

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => $tenantSecret,
    ]);

    // Global secret must not be used when tenant secret is present.
    config(['services.webhooks.meta_secret' => 'global-meta-secret']);

    $payload = json_encode([
        'id' => 'lead-tenant-'.Str::lower(Str::random(6)),
        'name' => 'Tenant Secret Lead',
        'phone' => '+966500000010',
    ], JSON_THROW_ON_ERROR);

    $signature = 'sha256='.hash_hmac('sha256', $payload, $tenantSecret);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenant->id}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('falls back to the global .env Meta secret when tenant secret is null', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => null,
    ]);

    $globalSecret = 'global-meta-secret-'.Str::lower(Str::random(8));
    config(['services.webhooks.meta_secret' => $globalSecret]);

    $payload = json_encode([
        'id' => 'lead-fallback-'.Str::lower(Str::random(6)),
        'name' => 'Fallback Lead',
        'phone' => '+966500000011',
    ], JSON_THROW_ON_ERROR);

    $signature = 'sha256='.hash_hmac('sha256', $payload, $globalSecret);

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenant->id}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);
    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('returns 401 when the signature does not match the tenant secret', function (): void {
    $tenant = Tenant::factory()->create();
    $tenantSecret = 'tenant-only-secret';

    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'meta_webhook_secret' => $tenantSecret,
    ]);

    config(['services.webhooks.meta_secret' => 'global-meta-secret']);

    $payload = json_encode([
        'id' => 'lead-bad-'.Str::lower(Str::random(6)),
        'name' => 'Bad Signature Lead',
        'phone' => '+966500000012',
    ], JSON_THROW_ON_ERROR);

    // Signed with the global secret while tenant secret is set → must fail.
    $signature = 'sha256='.hash_hmac('sha256', $payload, 'global-meta-secret');

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenant->id}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );

    $response->assertUnauthorized();
    $response->assertJson(['error' => 'unauthorized']);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});
