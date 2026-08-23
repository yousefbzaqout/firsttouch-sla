<?php

declare(strict_types=1);

use App\Adapters\Webhooks\MetaWebhookAdapter;
use App\Adapters\Webhooks\TikTokWebhookAdapter;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Events\SlaWarningBroadcastEvent;
use App\Filament\Widgets\LeadsByStatusChartWidget;
use App\Jobs\SendLeadAssignedNotificationJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use App\Services\Sla\SlaEscalationBufferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('rejects mock payment webhooks outside the local environment', function (): void {
    $this->app['env'] = 'production';

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'credits_balance' => 5,
        'notification_driver' => 'log',
    ]);

    $this->postJson('/api/v1/payments/webhook/mock', [
        'tenant_id' => $tenant->id,
        'transaction_id' => 'txn-audit-forbidden',
        'credits' => 999,
        'amount' => 1,
        'currency' => 'USD',
    ])->assertForbidden()
        ->assertJson(['error' => 'forbidden']);

    expect(
        TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('credits_balance'),
    )->toBe(5);
});

it('rejects telegram webhooks when the platform secret is empty', function (): void {
    config(['services.telegram.webhook_secret' => '']);

    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson('/api/v1/webhooks/telegram/'.$tenant->id, [
        'update_id' => 1,
        'callback_query' => ['id' => '1', 'data' => 'noop'],
    ])->assertUnauthorized()
        ->assertJson(['error' => 'unauthorized']);
});

it('rejects Meta and TikTok HMAC validation when the secret is empty', function (): void {
    $payload = '{"lead":"x"}';

    $metaRequest = Request::create('/', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, ''),
    ], $payload);

    $tiktokRequest = Request::create('/', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_TIKTOK_SIGNATURE' => hash_hmac('sha256', $payload, ''),
    ], $payload);

    expect((new MetaWebhookAdapter)->validateSignature($metaRequest, ''))->toBeFalse()
        ->and((new TikTokWebhookAdapter)->validateSignature($tiktokRequest, ''))->toBeFalse();
});

it('rejects a second concurrent claim with a validation error', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'UTC']);

    $repA = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);
    $repB = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
        'sla_status' => SlaStatus::Pending,
        'sla_deadline' => now()->addMinutes(5),
    ]);

    $workflow = app(LeadWorkflowService::class);

    $claimed = $workflow->claim($lead, $repA);

    expect($claimed->assigned_user_id)->toBe($repA->id);

    // Second claimer still holds a stale unassigned model instance (race simulation).
    $stale = Lead::withoutGlobalScopes()->findOrFail($lead->id);
    $stale->assigned_user_id = null;

    expect(fn () => $workflow->claim($stale, $repB))
        ->toThrow(ValidationException::class);

    expect(Lead::withoutGlobalScopes()->findOrFail($lead->id)->assigned_user_id)->toBe($repA->id);
});

it('does not dispatch SLA escalation notifications when the locked transaction early-returns', function (): void {
    Event::fake([SlaWarningBroadcastEvent::class]);
    Queue::fake([SendLeadAssignedNotificationJob::class]);

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
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
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
        'sla_warning_sent_at' => now()->subMinute(),
        'sla_escalated_at' => null,
        'first_action_at' => null,
        'claimed_at' => $started,
    ]);

    // Race: another worker already escalated in DB while this process still sees null.
    Lead::withoutGlobalScopes()->whereKey($lead->id)->update([
        'sla_escalated_at' => now()->subSeconds(5),
    ]);

    $method = new ReflectionMethod(SlaEscalationBufferService::class, 'escalateAndReassign');
    $method->invoke(app(SlaEscalationBufferService::class), $lead, now());

    Event::assertNotDispatched(SlaWarningBroadcastEvent::class);
    Queue::assertNotPushed(SendLeadAssignedNotificationJob::class);

    expect(Lead::withoutGlobalScopes()->findOrFail($lead->id)->assigned_user_id)->toBe($repA->id);
});

it('hides LeadsByStatusChartWidget from sales_rep users', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $this->actingAs($rep);

    expect(LeadsByStatusChartWidget::canView())->toBeFalse();
});
