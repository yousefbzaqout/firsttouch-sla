<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\KnowledgeBase;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('authenticated user can access filament admin panel', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $response = $this->actingAs($user)->get('/admin');

    expect($response->status())->toBeIn([200, 302]);
});

it('tenant 1 user can only see tenant 1 leads', function (): void {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);

    Lead::factory()->create(['tenant_id' => $tenant1->id, 'name' => 'Lead T1']);
    Lead::factory()->create(['tenant_id' => $tenant2->id, 'name' => 'Lead T2']);

    $this->actingAs($user1);

    $leads = Lead::all();

    expect($leads)->toHaveCount(1)
        ->and($leads->first()->name)->toBe('Lead T1');
});

it('tenant 1 user cannot see tenant 2 knowledge bases', function (): void {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);

    KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant1->id,
        'title' => 'KB T1',
        'type' => 'document',
        'is_active' => true,
    ]);

    KnowledgeBase::withoutGlobalScopes()->create([
        'tenant_id' => $tenant2->id,
        'title' => 'KB T2',
        'type' => 'document',
        'is_active' => true,
    ]);

    $this->actingAs($user1);

    $kbs = KnowledgeBase::all();

    expect($kbs)->toHaveCount(1)
        ->and($kbs->first()->title)->toBe('KB T1');
});

it('saving tenant settings updates database accurately', function (): void {
    $tenant = Tenant::factory()->create();
    $setting = TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'sla_timeout_minutes' => 5,
    ]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($user);

    $setting->update(['sla_timeout_minutes' => 15, 'timezone' => 'UTC']);

    expect($setting->fresh()->sla_timeout_minutes)->toBe(15)
        ->and($setting->fresh()->timezone)->toBe('UTC');
});
