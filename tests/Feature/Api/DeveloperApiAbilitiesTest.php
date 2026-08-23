<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows access to developer leads with a developer:* token ability', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $token = $owner->createToken('Developer API Token', ['developer:*'])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/developer/leads')
        ->assertOk();
});

it('denies access to developer leads when the token lacks developer:* ability', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
        'is_active' => true,
    ]);

    $readOnlyToken = $owner->createToken('read-only-token', ['read-only'])->plainTextToken;
    $emptyAbilitiesToken = $owner->createToken('no-scopes-token', [])->plainTextToken;

    $this->withToken($readOnlyToken)
        ->getJson('/api/v1/developer/leads')
        ->assertForbidden();

    $this->withToken($emptyAbilitiesToken)
        ->getJson('/api/v1/developer/leads')
        ->assertForbidden();
});
