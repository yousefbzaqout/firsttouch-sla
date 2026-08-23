<?php

declare(strict_types=1);

use App\DTOs\LeadData;
use App\Enums\LeadSource;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Pipelines\LeadProcessingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
});

it('processes a lead through the full pipeline', function (): void {
    $externalLeadId = 'ext-'.Str::lower(Str::random(8));

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'sla_timeout_minutes' => 10,
        'timezone' => 'UTC',
    ]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $leadData = new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: $externalLeadId,
        name: 'Test Lead',
        phone: '+966500000000',
        email: 'test@example.com',
        campaignId: 'camp-1',
        formId: 'form-1',
        rawPayload: ['key' => 'value'],
    );

    $pipeline = app(LeadProcessingPipeline::class);
    $lead = $pipeline->process($leadData);

    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($lead->tenant_id)->toBe($tenant->id)
        ->and($lead->external_lead_id)->toBe($externalLeadId)
        ->and($lead->sla_status)->toBe(SlaStatus::Active)
        ->and($lead->sla_deadline)->not->toBeNull()
        ->and($lead->assigned_user_id)->not->toBeNull();
});

it('returns the existing lead for duplicate external_lead_id (idempotent)', function (): void {
    $externalLeadId = 'ext-'.Str::lower(Str::random(8));

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'UTC',
    ]);
    TenantWorkingHour::create([
        'tenant_id' => $tenant->id,
        'day_of_week' => (int) now()->timezone('UTC')->dayOfWeek,
        'start_time' => '00:00',
        'end_time' => '23:59',
        'off_hours_action' => 'freeze_sla',
    ]);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $leadData = new LeadData(
        tenantId: $tenant->id,
        source: LeadSource::Meta,
        externalLeadId: $externalLeadId,
        name: 'Dup Lead',
        phone: '+966500000001',
        email: null,
        campaignId: null,
        formId: null,
        rawPayload: [],
    );

    $pipeline = app(LeadProcessingPipeline::class);
    $first = $pipeline->process($leadData);
    $second = $pipeline->process($leadData);

    expect($first)->toBeInstanceOf(Lead::class)
        ->and($second)->toBeInstanceOf(Lead::class)
        ->and($second?->id)->toBe($first?->id);

    expect(Lead::withoutGlobalScopes()->where('external_lead_id', $externalLeadId)->count())->toBe(1);
});
