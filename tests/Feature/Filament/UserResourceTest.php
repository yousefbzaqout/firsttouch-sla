<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Leads\RoundRobinAssignerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows a tenant owner to create a sales rep via filament', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Sara Sales',
            'email' => 'sara@acme.test',
            'password' => 'Password1!',
            'role' => UserRole::SalesRep->value,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('index'));

    $salesRep = User::query()->where('email', 'sara@acme.test')->first();

    expect($salesRep)->not->toBeNull()
        ->and($salesRep->name)->toBe('Sara Sales')
        ->and($salesRep->tenant_id)->toBe($tenant->id)
        ->and($salesRep->role)->toBe(UserRole::SalesRep)
        ->and($salesRep->is_active)->toBeTrue();
});

it('allows a tenant owner to toggle a sales rep active status', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    Livewire::test(ListUsers::class)
        ->callTableAction('toggleActive', $salesRep)
        ->assertHasNoTableActionErrors();

    expect($salesRep->fresh()->is_active)->toBeFalse();

    $assignedUserId = app(RoundRobinAssignerService::class)->assign($tenant->id);

    expect($assignedUserId)->toBeNull();
});

it('forbids sales reps from accessing the user resource', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $this->actingAs($salesRep);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(UserResource::canCreate())->toBeFalse();

    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(UserResource::getUrl('create'))->assertForbidden();

    Livewire::test(ListUsers::class)->assertForbidden();
    Livewire::test(CreateUser::class)->assertForbidden();
});

it('isolates team members across tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenantA->id]);
    TenantSetting::factory()->create(['tenant_id' => $tenantB->id]);

    $ownerA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::Owner,
    ]);

    $repA = User::factory()->create([
        'tenant_id' => $tenantA->id,
        'role' => UserRole::SalesRep,
        'name' => 'Rep A',
    ]);

    $repB = User::factory()->create([
        'tenant_id' => $tenantB->id,
        'role' => UserRole::SalesRep,
        'name' => 'Rep B',
    ]);

    $this->actingAs($ownerA);

    $visibleIds = UserResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)->toContain($repA->id)
        ->and($visibleIds)->not->toContain($repB->id)
        ->and($visibleIds)->not->toContain($ownerA->id);

    $this->get(UserResource::getUrl('edit', ['record' => $repB]))->assertNotFound();
});
