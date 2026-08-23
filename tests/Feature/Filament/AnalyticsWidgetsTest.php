<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\AgentLeaderboardWidget;
use App\Filament\Widgets\LeadSourcesChartWidget;
use App\Filament\Widgets\SlaStatsOverviewWidget;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders SlaStatsOverviewWidget with correct compliance and response time for the tenant', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'UTC']);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $agent = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $created = now()->subMinutes(5);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::Contacted,
        'sla_status' => SlaStatus::Met,
        'created_at' => $created,
        'updated_at' => $created,
        'first_action_at' => $created->copy()->addSeconds(135),
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::Lost,
        'sla_status' => SlaStatus::Breached,
        'created_at' => $created,
        'first_action_at' => $created->copy()->addSeconds(135),
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Active,
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $agent->id,
        'status' => LeadStatus::New,
        'sla_status' => SlaStatus::Frozen,
    ]);

    $this->actingAs($owner);

    expect(SlaStatsOverviewWidget::canView())->toBeTrue()
        ->and(SlaStatsOverviewWidget::formatDuration(135.0))->toBe('2m 15s');

    Livewire::test(SlaStatsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('SLA Compliance Rate')
        ->assertSee('50%')
        ->assertSee('Avg Response Time')
        ->assertSee('2m 15s')
        ->assertSee('Active Queue')
        ->assertSee('2');
});

it('lists agents with metric totals on AgentLeaderboardWidget', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id, 'timezone' => 'UTC']);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Admin,
        'is_active' => true,
    ]);

    $sara = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Sara Analytics',
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $omar = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Omar Analytics',
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => false,
    ]);

    $created = now()->subMinutes(10);

    Lead::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $sara->id,
        'status' => LeadStatus::Closed,
        'sla_status' => SlaStatus::Met,
        'source' => LeadSource::Meta,
        'created_at' => $created,
        'first_action_at' => $created->copy()->addMinutes(1),
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $omar->id,
        'status' => LeadStatus::Claimed,
        'sla_status' => SlaStatus::Breached,
        'source' => LeadSource::TikTok,
    ]);

    $this->actingAs($owner);

    expect(AgentLeaderboardWidget::canView())->toBeTrue();

    Livewire::test(AgentLeaderboardWidget::class)
        ->assertSuccessful()
        ->assertSee('Sales Team Performance')
        ->assertSee('Sara Analytics')
        ->assertSee('Omar Analytics')
        ->assertCanSeeTableRecords([$sara, $omar]);
});

it('hides tenant-wide analytics widgets from sales_rep users', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $rep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
    ]);

    $this->actingAs($rep);

    expect(SlaStatsOverviewWidget::canView())->toBeFalse()
        ->and(LeadSourcesChartWidget::canView())->toBeFalse()
        ->and(AgentLeaderboardWidget::canView())->toBeFalse();

    Livewire::test(SlaStatsOverviewWidget::class)->assertForbidden();
    Livewire::test(LeadSourcesChartWidget::class)->assertForbidden();
    Livewire::test(AgentLeaderboardWidget::class)->assertForbidden();
});
