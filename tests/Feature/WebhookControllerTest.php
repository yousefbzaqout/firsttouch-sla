<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ProcessLeadIngestionJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    Bus::fake();
});

it('dispatches ProcessLeadIngestionJob and returns 200 for valid Meta HMAC', function (): void {
    $externalLeadId = 'lead-ext-'.Str::lower(Str::random(8));

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $payload = json_encode([
        'id' => $externalLeadId,
        'name' => 'Test User',
        'phone' => '+966500000000',
        'email' => 'test@example.com',
    ]);

    $secret = 'test-secret';
    config(['services.webhooks.meta_secret' => $secret]);

    $signature = 'sha256='.hash_hmac('sha256', (string) $payload, $secret);

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
        (string) $payload,
    );

    $response->assertOk();
    $response->assertJson(['status' => 'accepted']);

    Bus::assertDispatched(ProcessLeadIngestionJob::class);
});

it('returns 401 for invalid HMAC signature', function (): void {
    $tenant = Tenant::factory()->create();

    config(['services.webhooks.meta_secret' => 'real-secret']);

    $payload = json_encode(['id' => 'lead-002', 'name' => 'Bad', 'phone' => '+966']);
    $badSignature = 'sha256=invalidsignaturehere';

    $response = $this->call(
        'POST',
        "/api/v1/webhooks/meta/{$tenant->id}",
        [],
        [],
        [],
        [
            'HTTP_X-Hub-Signature-256' => $badSignature,
            'CONTENT_TYPE' => 'application/json',
        ],
        (string) $payload,
    );

    $response->assertStatus(401);
    Bus::assertNotDispatched(ProcessLeadIngestionJob::class);
});

it('returns already_processed for duplicate external_lead_id', function (): void {
    $externalLeadId = 'lead-dup-'.Str::lower(Str::random(8));

    $tenant = Tenant::factory()->create();

    $secret = 'test-secret';
    config(['services.webhooks.meta_secret' => $secret]);

    $payload = json_encode([
        'id' => $externalLeadId,
        'name' => 'Dup Lead',
        'phone' => '+966500000001',
    ]);

    $signature = 'sha256='.hash_hmac('sha256', (string) $payload, $secret);
    $headers = [
        'HTTP_X-Hub-Signature-256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ];

    $first = $this->call('POST', "/api/v1/webhooks/meta/{$tenant->id}", [], [], [], $headers, (string) $payload);
    $first->assertOk();

    $second = $this->call('POST', "/api/v1/webhooks/meta/{$tenant->id}", [], [], [], $headers, (string) $payload);
    $second->assertOk();
    $second->assertJson(['status' => 'already_processed']);

    Bus::assertDispatchedTimes(ProcessLeadIngestionJob::class, 1);
});
