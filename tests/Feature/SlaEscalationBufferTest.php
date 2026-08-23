<?php

declare(strict_types=1);

use App\Adapters\Notifications\NotificationDriverFactory;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessSlaEscalationBuffersJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use App\Services\Sla\SlaEscalationBufferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function seedSlaBufferTenant(): array
{
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'sla_timeout_minutes' => 10,
        'notification_driver' => 'log',
        'timezone' => 'UTC',
    ]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);

    $repA = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'name' => 'Rep A',
    ]);
    $repB = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'name' => 'Rep B',
    ]);

    return compact('tenant', 'repA', 'repB');
}

it('sends a 50% SLA warning to the assigned agent when the lead is still pending', function (): void {
    ['tenant' => $tenant, 'repA' => $repA] = seedSlaBufferTenant();

    $started = now()->subMinutes(6);
    $deadline = now()->addMinutes(4);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => $deadline,
        'sla_warning_sent_at' => null,
        'sla_escalated_at' => null,
        'first_action_at' => null,
        'claimed_at' => $started,
    ]);

    $service = app(SlaEscalationBufferService::class);

    expect($service->progress($lead))->toBeGreaterThanOrEqual(0.50)
        ->and($service->processLead($lead))->toBeTrue();

    $lead->refresh();

    expect($lead->sla_warning_sent_at)->not->toBeNull()
        ->and($lead->assigned_user_id)->toBe($repA->id)
        ->and($lead->sla_escalated_at)->toBeNull();
});

it('auto-unassigns and reassigns to the next online agent at 80% SLA', function (): void {
    ['tenant' => $tenant, 'repA' => $repA, 'repB' => $repB] = seedSlaBufferTenant();

    $started = now()->subMinutes(9);
    $deadline = now()->addMinute();

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => $deadline,
        'sla_warning_sent_at' => $started->copy()->addMinutes(5),
        'sla_escalated_at' => null,
        'first_action_at' => null,
        'claimed_at' => $started,
        'name' => 'Buffer Escalation Lead',
    ]);

    $service = app(SlaEscalationBufferService::class);

    expect($service->progress($lead))->toBeGreaterThanOrEqual(0.80)
        ->and($service->processLead($lead))->toBeTrue();

    $lead->refresh();

    expect($lead->sla_escalated_at)->not->toBeNull()
        ->and($lead->previous_assigned_user_id)->toBe($repA->id)
        ->and($lead->assigned_user_id)->toBe($repB->id)
        ->and($lead->status)->toBe(LeadStatus::Claimed)
        ->and($lead->sla_status)->toBe(SlaStatus::Active)
        ->and($lead->sla_warning_sent_at)->toBeNull()
        ->and($lead->sla_started_at?->greaterThan($started))->toBeTrue();
});

it('stops SLA buffer timers when the lead is marked contacted before 50%', function (): void {
    ['tenant' => $tenant, 'repA' => $repA] = seedSlaBufferTenant();

    $started = now()->subMinutes(2);
    $deadline = now()->addMinutes(8);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => $deadline,
        'first_action_at' => null,
        'claimed_at' => $started,
    ]);

    app(LeadWorkflowService::class)->markContacted($lead, $repA);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->first_action_at)->not->toBeNull()
        ->and(app(SlaEscalationBufferService::class)->isAwaitingAgentAction($lead))->toBeFalse()
        ->and(app(SlaEscalationBufferService::class)->processLead($lead))->toBeFalse()
        ->and($lead->fresh()->sla_warning_sent_at)->toBeNull()
        ->and($lead->fresh()->sla_escalated_at)->toBeNull();
});

it('stops SLA buffer timers when the lead is marked in progress', function (): void {
    ['tenant' => $tenant, 'repA' => $repA] = seedSlaBufferTenant();

    $started = now()->subMinutes(7);
    $deadline = now()->addMinutes(3);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => $deadline,
        'first_action_at' => null,
    ]);

    app(LeadWorkflowService::class)->markInProgress($lead, $repA);
    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::InProgress)
        ->and(app(SlaEscalationBufferService::class)->processLead($lead))->toBeFalse()
        ->and($lead->fresh()->assigned_user_id)->toBe($repA->id)
        ->and($lead->fresh()->sla_escalated_at)->toBeNull();
});

it('skips offline agents during 80% auto-reassignment', function (): void {
    ['tenant' => $tenant, 'repA' => $repA, 'repB' => $repB] = seedSlaBufferTenant();
    $repB->update(['is_online' => false]);

    $repC = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'name' => 'Rep C',
    ]);

    $started = now()->subMinutes(9);
    $deadline = now()->addMinute();

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => $started,
        'sla_deadline' => $deadline,
        'sla_warning_sent_at' => now()->subMinutes(2),
        'first_action_at' => null,
    ]);

    app(SlaEscalationBufferService::class)->processLead($lead);
    $lead->refresh();

    expect($lead->assigned_user_id)->toBe($repC->id)
        ->and($lead->assigned_user_id)->not->toBe($repB->id);
});

it('dispatches the escalation buffer job from the artisan command', function (): void {
    Queue::fake();

    $this->artisan('sla:check-escalation-buffers')->assertSuccessful();

    Queue::assertPushed(ProcessSlaEscalationBuffersJob::class);
});

it('runs buffer checks inline with --sync', function (): void {
    ['tenant' => $tenant, 'repA' => $repA] = seedSlaBufferTenant();

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
        'sla_started_at' => now()->subMinutes(6),
        'sla_deadline' => now()->addMinutes(4),
        'first_action_at' => null,
    ]);

    $this->artisan('sla:check-escalation-buffers', ['--sync' => true])->assertSuccessful();

    expect($lead->fresh()->sla_warning_sent_at)->not->toBeNull();
});

it('builds telegram templates for halfway and reassignment warnings', function (): void {
    Http::fake();

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'notification_driver' => 'telegram',
        'telegram_bot_token' => 'TOKEN',
        'telegram_chat_id' => '-1001',
    ]);

    $previous = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 111,
        'name' => 'Old Rep',
    ]);
    $next = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'telegram_chat_id' => 222,
        'name' => 'New Rep',
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'telegram_chat_id' => 333,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $previous->id,
        'name' => 'Template Lead',
        'phone' => '+966500011122',
        'sla_deadline' => now()->addMinutes(3),
        'meta_data' => ['interested_service' => 'Retainer'],
    ]);

    $driver = app(NotificationDriverFactory::class)->resolve('telegram');

    expect($driver->sendSlaHalfwayWarning($lead))->toBeTrue();
    expect($driver->sendSlaReassignmentWarning($lead, $previous, $next))->toBeTrue();

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '50%');
    });

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '80%');
    });
});
