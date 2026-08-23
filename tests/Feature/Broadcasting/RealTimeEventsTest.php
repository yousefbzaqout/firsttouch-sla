<?php

declare(strict_types=1);

use App\Broadcasting\TenantChannel;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Events\LeadIngestedEvent;
use App\Events\SlaWarningBroadcastEvent;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\Broadcast;

uses(RefreshDatabase::class);

it('broadcasts LeadIngestedEvent on the private tenant channel', function (): void {
    Event::fake([LeadIngestedEvent::class]);

    $tenant = Tenant::factory()->create();
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Realtime Lead',
        'source' => LeadSource::Meta,
    ]);

    LeadIngestedEvent::dispatch($lead);

    Broadcast::assertBroadcasted(LeadIngestedEvent::class, function (LeadIngestedEvent $event) use ($tenant, $lead): bool {
        $channels = collect($event->broadcastOn());

        expect($channels)->toHaveCount(1)
            ->and($channels->first())->toBeInstanceOf(PrivateChannel::class)
            ->and($channels->first()->name)->toBe('private-tenant.'.$tenant->id);

        $payload = $event->broadcastWith();

        return $payload['lead_id'] === (string) $lead->id
            && $payload['name'] === 'Realtime Lead'
            && $payload['source'] === LeadSource::Meta->value
            && array_key_exists('created_at', $payload);
    });
});

it('includes the exact SlaWarningBroadcastEvent payload fields', function (): void {
    Event::fake([SlaWarningBroadcastEvent::class]);

    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'name' => 'Sara Agent',
        'is_active' => true,
        'is_online' => true,
    ]);

    $deadline = now()->addMinutes(4);
    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'sla_deadline' => $deadline,
    ]);
    $lead->load('assignedUser');

    SlaWarningBroadcastEvent::dispatch($lead, 50);

    Broadcast::assertBroadcasted(SlaWarningBroadcastEvent::class, function (SlaWarningBroadcastEvent $event) use ($lead, $deadline): bool {
        $payload = $event->broadcastWith();
        $expectedRemaining = max(0, $deadline->getTimestamp() - now()->getTimestamp());

        return $payload['lead_id'] === (string) $lead->id
            && $payload['assigned_user_name'] === 'Sara Agent'
            && $payload['threshold_percent'] === 50
            && $payload['time_remaining'] === $expectedRemaining
            && collect($event->broadcastOn())->first()?->name === 'private-tenant.'.$lead->tenant_id;
    });
});

it('denies tenant B users from authorizing tenant A private channels', function (): void {
    config(['broadcasting.default' => 'redis']);

    app()->forgetInstance(Factory::class);
    app()->forgetInstance(Broadcaster::class);
    require base_path('routes/channels.php');

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenantA->id]);
    TenantSetting::factory()->create(['tenant_id' => $tenantB->id]);

    $userA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $userB = User::factory()->create([
        'tenant_id' => $tenantB->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $authorizer = app(TenantChannel::class);

    expect($authorizer->join($userA, $tenantA->id))->toBeTrue()
        ->and($authorizer->join($userB, $tenantA->id))->toBeFalse();

    $this->actingAs($userA);
    $this->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant.'.$tenantA->id,
        'socket_id' => '1234.5678',
    ])->assertSuccessful();

    $this->actingAs($userB);
    $this->post('/broadcasting/auth', [
        'channel_name' => 'private-tenant.'.$tenantA->id,
        'socket_id' => '1234.5678',
    ])->assertForbidden();
});
