<?php

declare(strict_types=1);

use App\DTOs\LeadData;
use App\Enums\AiRoutingMode;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\OffHoursAction;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use App\Services\Sla\BusinessHoursService;
use App\Services\Sla\SlaEscalationBufferService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: Tenant, 1: User}
 */
function seedBusinessHoursTenant(string $timezone = 'UTC'): array
{
    $tenant = Tenant::factory()->create(['is_active' => true]);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => $timezone,
        'sla_timeout_minutes' => 30,
        'ai_routing_mode' => AiRoutingMode::HumanFirst,
        'credits_balance' => 20,
    ]);

    // Mon–Fri 09:00–17:00 UTC (Carbon dayOfWeek: 1=Mon … 5=Fri)
    foreach ([1, 2, 3, 4, 5] as $day) {
        TenantWorkingHour::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'day_of_week' => $day,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'off_hours_action' => OffHoursAction::FreezeSla,
        ]);
    }

    $agent = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    return [$tenant, $agent];
}

it('starts the SLA timer immediately when a lead arrives during working hours', function (): void {
    Bus::fake();
    [$tenant] = seedBusinessHoursTenant();

    // Wednesday 2026-08-19 10:00 UTC — inside 09:00–17:00
    Carbon::setTestNow(Carbon::parse('2026-08-19 10:00:00', 'UTC'));

    $leadData = new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Manual,
        externalLeadId: 'bh-in-hours-1',
        name: 'In Hours Lead',
        phone: '+966500000001',
        email: null,
        campaignId: null,
        formId: null,
        rawPayload: [],
    );

    $lead = app(LeadProcessingPipeline::class)->process($leadData);

    expect($lead)->not->toBeNull()
        ->and($lead?->sla_status)->toBe(SlaStatus::Active)
        ->and($lead?->sla_started_at)->not->toBeNull()
        ->and($lead?->sla_started_at?->format('Y-m-d H:i'))->toBe('2026-08-19 10:00')
        ->and($lead?->sla_deadline)->not->toBeNull()
        ->and($lead?->sla_deadline?->format('Y-m-d H:i'))->toBe('2026-08-19 10:30');
});

it('freezes SLA and schedules sla_started_at for next open when lead arrives off-hours', function (): void {
    Bus::fake();
    [$tenant] = seedBusinessHoursTenant();

    // Wednesday 2026-08-19 20:00 UTC — after 17:00 close
    Carbon::setTestNow(Carbon::parse('2026-08-19 20:00:00', 'UTC'));

    expect(app(BusinessHoursService::class)->isWorkingHour($tenant))->toBeFalse();

    $nextOpen = app(BusinessHoursService::class)->getNextWorkingHourStart($tenant);
    expect($nextOpen->format('Y-m-d H:i'))->toBe('2026-08-20 09:00');

    $leadData = new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Manual,
        externalLeadId: 'bh-off-hours-1',
        name: 'Off Hours Lead',
        phone: '+966500000002',
        email: null,
        campaignId: null,
        formId: null,
        rawPayload: [],
    );

    $lead = app(LeadProcessingPipeline::class)->process($leadData);

    expect($lead)->not->toBeNull()
        ->and($lead?->sla_status)->toBe(SlaStatus::Frozen)
        ->and($lead?->sla_started_at?->format('Y-m-d H:i'))->toBe('2026-08-20 09:00')
        ->and($lead?->sla_deadline?->format('Y-m-d H:i'))->toBe('2026-08-20 09:30');
});

it('ignores Frozen leads in escalation buffers until opening time passes, then unfreezes', function (): void {
    Bus::fake();
    [$tenant, $agent] = seedBusinessHoursTenant();

    Carbon::setTestNow(Carbon::parse('2026-08-19 20:00:00', 'UTC'));

    $lead = Lead::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'source' => LeadSource::Manual,
        'external_lead_id' => 'bh-frozen-buffer-1',
        'name' => 'Frozen Buffer Lead',
        'phone' => '+966500000003',
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Frozen,
        'sla_started_at' => Carbon::parse('2026-08-20 09:00:00', 'UTC'),
        'sla_deadline' => Carbon::parse('2026-08-20 09:30:00', 'UTC'),
        'claimed_at' => now(),
        'meta_data' => [],
    ]);

    $buffers = app(SlaEscalationBufferService::class);

    // Still before open — frozen lead must be ignored (no warning / no escalate).
    $processedBeforeOpen = $buffers->processDueLeads(Carbon::parse('2026-08-20 08:59:00', 'UTC'));
    $lead->refresh();

    expect($processedBeforeOpen)->toBe(0)
        ->and($lead->sla_status)->toBe(SlaStatus::Frozen)
        ->and($lead->sla_warning_sent_at)->toBeNull()
        ->and($buffers->isAwaitingAgentAction($lead))->toBeFalse();

    // At opening — unfreeze to Active so buffers can begin.
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));
    $thawed = $buffers->unfreezeDueLeads(Carbon::now());
    $lead->refresh();

    expect($thawed)->toBe(1)
        ->and($lead->sla_status)->toBe(SlaStatus::Active)
        ->and($lead->sla_started_at?->format('Y-m-d H:i'))->toBe('2026-08-20 09:00')
        ->and($buffers->isAwaitingAgentAction($lead))->toBeTrue();
});
