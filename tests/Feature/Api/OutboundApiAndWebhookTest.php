<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\DispatchOutboundWebhookJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches an outbound webhook with HMAC signature when lead status changes', function (): void {
    Http::fake([
        'https://crm.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'outbound_webhook_url' => 'https://crm.example.test/hooks/firsttouch',
        'outbound_webhook_secret' => 'outbound-secret-123',
    ]);

    $agent = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'name' => 'Outbound Agent',
        'is_active' => true,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::Claimed,
        'name' => 'Webhook Lead',
        'phone' => '+966500009999',
    ]);

    $lead->update(['status' => LeadStatus::Contacted]);

    (new DispatchOutboundWebhookJob($lead->id, 'lead.contacted'))->handle();

    Http::assertSent(function ($request) use ($lead): bool {
        if ($request->url() !== 'https://crm.example.test/hooks/firsttouch') {
            return false;
        }

        $body = $request->body();
        $expected = hash_hmac('sha256', $body, 'outbound-secret-123');

        if ($request->header('X-FirstTouch-Signature')[0] !== $expected) {
            return false;
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return ($payload['event'] ?? null) === 'lead.contacted'
            && ($payload['lead_id'] ?? null) === (string) $lead->id
            && ($payload['name'] ?? null) === 'Webhook Lead'
            && ($payload['phone'] ?? null) === '+966500009999'
            && ($payload['status'] ?? null) === LeadStatus::Contacted->value
            && ($payload['assigned_user'] ?? null) === 'Outbound Agent'
            && array_key_exists('sla_breached', $payload)
            && array_key_exists('timestamp', $payload);
    });
});

it('queues DispatchOutboundWebhookJob when a lead status is updated', function (): void {
    Queue::fake();

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'outbound_webhook_url' => 'https://crm.example.test/hooks/firsttouch',
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => LeadStatus::Claimed,
    ]);

    $lead->update(['status' => LeadStatus::Closed]);

    Queue::assertPushed(DispatchOutboundWebhookJob::class);
});

it('returns tenant-isolated leads for sanctum-authenticated developer API', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenantA->id]);
    TenantSetting::factory()->create(['tenant_id' => $tenantB->id]);

    $ownerA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $leadA = Lead::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Tenant A Lead',
        'status' => LeadStatus::Contacted,
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Tenant B Lead',
        'status' => LeadStatus::Contacted,
    ]);

    $token = $ownerA->createToken('test-crm', ['developer:*'])->plainTextToken;

    $list = $this->withToken($token)->getJson('/api/v1/developer/leads?status=contacted');
    $list->assertOk()
        ->assertJsonPath('data.0.id', (string) $leadA->id)
        ->assertJsonMissing(['name' => 'Tenant B Lead']);

    $show = $this->withToken($token)->getJson('/api/v1/developer/leads/'.$leadA->id);
    $show->assertOk()
        ->assertJsonPath('data.name', 'Tenant A Lead')
        ->assertJsonPath('data.status', LeadStatus::Contacted->value);

    $summary = $this->withToken($token)->getJson('/api/v1/developer/analytics/sla-summary');
    $summary->assertOk()
        ->assertJsonStructure([
            'data' => [
                'compliance_rate',
                'average_response_seconds',
                'total_breaches',
                'total_leads',
                'met_count',
            ],
        ]);
});

it('returns 401 for unauthenticated developer API requests', function (): void {
    $this->getJson('/api/v1/developer/leads')->assertUnauthorized();
    $this->getJson('/api/v1/developer/analytics/sla-summary')->assertUnauthorized();
});

it('does not expose another tenant lead via show endpoint', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenantA->id]);

    $ownerA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);

    $leadB = Lead::factory()->create([
        'tenant_id' => $tenantB->id,
        'sla_status' => SlaStatus::Breached,
    ]);

    $token = $ownerA->createToken('iso', ['developer:*'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/developer/leads/'.$leadB->id)
        ->assertNotFound();
});
