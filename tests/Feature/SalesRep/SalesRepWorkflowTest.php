<?php

declare(strict_types=1);

use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Pages\TenantSettingsPage;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Filament\Resources\UserResource;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Leads\LeadWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows sales reps to see only assigned and unassigned leads', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $otherRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $assigned = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $salesRep->id,
        'name' => 'Mine',
    ]);

    $unassigned = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => null,
        'name' => 'Open',
    ]);

    $otherAssigned = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $otherRep->id,
        'name' => 'Theirs',
    ]);

    $this->actingAs($salesRep);

    $visibleIds = LeadResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($assigned->id, $unassigned->id)
        ->and($visibleIds)->not->toContain($otherAssigned->id);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$assigned, $unassigned])
        ->assertCanNotSeeTableRecords([$otherAssigned]);
});

it('forbids sales reps from accessing team management and tenant settings', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $this->actingAs($salesRep);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(TenantSettingsPage::canAccess())->toBeFalse();

    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(TenantSettingsPage::getUrl())->assertForbidden();
});

it('allows a sales rep to claim an unassigned lead', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => null,
        'status' => LeadStatus::New,
    ]);

    $this->actingAs($salesRep);

    Livewire::test(ListLeads::class)
        ->callTableAction('claim', $lead)
        ->assertHasNoTableActionErrors();

    $lead->refresh();

    expect($lead->assigned_user_id)->toBe($salesRep->id)
        ->and($lead->status)->toBe(LeadStatus::Claimed)
        ->and($lead->claimed_at)->not->toBeNull();
});

it('halts the sla breach timer when a sales rep marks a lead as contacted', function (): void {
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
        'sla_status' => SlaStatus::Active,
        'sla_deadline' => now()->subMinute(),
    ]);

    $this->actingAs($salesRep);

    app(LeadWorkflowService::class)->markContacted($lead, $salesRep);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Contacted)
        ->and($lead->sla_status)->toBe(SlaStatus::Met)
        ->and($lead->first_action_at)->not->toBeNull();

    $this->artisan('sla:check-breaches')->assertSuccessful();

    expect($lead->fresh()->sla_status)->toBe(SlaStatus::Met);
});

it('allows tenant owners to see all leads across sales reps', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $repA = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $repB = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $leadA = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repA->id,
        'name' => 'Lead A',
    ]);

    $leadB = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $repB->id,
        'name' => 'Lead B',
    ]);

    $open = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => null,
        'name' => 'Open Lead',
    ]);

    $this->actingAs($owner);

    $visibleIds = LeadResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($leadA->id, $leadB->id, $open->id);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$leadA, $leadB, $open]);
});
