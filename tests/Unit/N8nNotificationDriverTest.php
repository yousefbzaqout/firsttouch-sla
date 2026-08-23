<?php

declare(strict_types=1);

use App\Adapters\Notifications\N8nNotificationDriver;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('sends SLA alert to n8n webhook with correct payload', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $driver = new N8nNotificationDriver('https://n8n.example.com/webhook/sla');
    $result = $driver->sendSlaAlert($lead, 'SLA approaching deadline');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) use ($lead): bool {
        return $request->url() === 'https://n8n.example.com/webhook/sla'
            && $request['event_type'] === 'sla_warning'
            && $request['type'] === 'sla_alert'
            && $request['lead_id'] === $lead->id
            && $request['message'] === 'SLA approaching deadline';
    });
});

it('sends escalation alert to n8n webhook', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

    $driver = new N8nNotificationDriver('https://n8n.example.com/webhook/sla');
    $result = $driver->sendEscalationAlert($lead, 'Lead escalated');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request['event_type'] === 'sla_breach'
            && $request['type'] === 'escalation';
    });
});

it('sends account status alert to n8n webhook', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'n8n',
    ]);

    $driver = new N8nNotificationDriver('https://n8n.example.com/webhook/sla');
    $result = $driver->sendAccountStatusAlert($tenant->id, 'Low AI credits warning: 3 remaining');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) use ($tenant): bool {
        return $request->url() === 'https://n8n.example.com/webhook/sla'
            && $request['event_type'] === 'credit_low'
            && $request['type'] === 'account_status'
            && $request['tenant_id'] === $tenant->id;
    });
});
