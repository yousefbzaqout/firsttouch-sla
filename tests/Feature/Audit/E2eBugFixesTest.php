<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Pages\TenantSettingsPage;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\LeadResource\Pages\ViewLead;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use App\Support\Timezones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('rejects invalid timezones when saving tenant settings', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'Asia/Riyadh',
        'notification_driver' => 'log',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    Livewire::test(TenantSettingsPage::class)
        ->fillForm([
            'sla_timeout_minutes' => 10,
            'ai_confidence_threshold' => 80,
            'ai_selected_model' => 'openrouter/free',
            'ai_routing_mode' => 'human_only',
            'notification_driver' => 'log',
            'timezone' => 'NotAReal/Zone',
        ])
        ->call('save')
        ->assertHasFormErrors(['timezone']);

    expect(TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('timezone'))
        ->toBe('Asia/Riyadh');
});

it('accepts valid IANA timezones when saving tenant settings', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'UTC',
        'notification_driver' => 'log',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    Livewire::test(TenantSettingsPage::class)
        ->fillForm([
            'sla_timeout_minutes' => 10,
            'ai_confidence_threshold' => 80,
            'ai_selected_model' => 'openrouter/free',
            'ai_routing_mode' => 'human_only',
            'notification_driver' => 'log',
            'timezone' => 'Asia/Riyadh',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('timezone'))
        ->toBe('Asia/Riyadh');
});

it('coerces corrupt lead status values instead of crashing the leads list', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Corrupt Status Lead',
        'status' => LeadStatus::New,
    ]);

    DB::table('leads')->where('id', $lead->id)->update(['status' => 'pending']);

    Log::spy();

    $this->actingAs($owner);

    $hydrated = Lead::withoutGlobalScopes()->findOrFail($lead->id);

    expect($hydrated->status)->toBe(LeadStatus::New);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$hydrated]);
});

it('allows updating a lead to in progress from the view page', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $salesRep->id,
        'status' => LeadStatus::Claimed,
    ]);

    $this->actingAs($salesRep);

    Livewire::test(ViewLead::class, ['record' => $lead->getKey()])
        ->callAction('updateStatus', data: [
            'status' => LeadStatus::InProgress->value,
        ])
        ->assertHasNoActionErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::InProgress)
        ->and($lead->fresh()->first_action_at)->not->toBeNull();
});

it('stores blank external lead ids as null so multiple blank ids can coexist', function (): void {
    $tenant = Tenant::factory()->create();

    $first = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'external_lead_id' => '',
    ]);

    $second = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'external_lead_id' => '',
    ]);

    expect($first->fresh()->external_lead_id)->toBeNull()
        ->and($second->fresh()->external_lead_id)->toBeNull()
        ->and(Timezones::isValid('Asia/Riyadh'))->toBeTrue()
        ->and(Timezones::isValid('NotAReal/Zone'))->toBeFalse()
        ->and(Timezones::resolve('NotAReal/Zone'))->toBe('UTC');
});

it('marks a claimed lead in progress through the workflow service', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $salesRep->id,
        'status' => LeadStatus::Claimed,
    ]);

    app(LeadWorkflowService::class)->updateStatus($lead, LeadStatus::InProgress, $salesRep);

    expect($lead->fresh()->status)->toBe(LeadStatus::InProgress);
});
