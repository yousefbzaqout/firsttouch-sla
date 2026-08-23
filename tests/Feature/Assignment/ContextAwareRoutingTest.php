<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Assignment\ContextAwareRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('routes the lead to the agent with the highest skills tag match score', function (): void {
    $tenant = Tenant::factory()->create();

    $generalist = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['English'],
    ]);

    $specialist = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['English', 'VIP', 'Technical'],
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'routing_tags' => ['English', 'VIP', 'Technical'],
        'assigned_user_id' => null,
    ]);

    $assigned = app(ContextAwareRoutingService::class)->assign($lead);

    expect($assigned)->not->toBeNull()
        ->and($assigned->id)->toBe($specialist->id)
        ->and($assigned->id)->not->toBe($generalist->id);
});

it('uses round-robin as a tie-breaker among equally scoring agents', function (): void {
    $tenant = Tenant::factory()->create();

    $first = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['English'],
    ]);

    $second = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['English'],
    ]);

    expect($first->id)->toBeLessThan($second->id);

    // Seed last assignment to $first so round-robin advances to $second.
    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $first->id,
        'routing_tags' => ['English'],
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'routing_tags' => ['English'],
        'assigned_user_id' => null,
    ]);

    $assigned = app(ContextAwareRoutingService::class)->assign($lead);

    expect($assigned)->not->toBeNull()
        ->and($assigned->id)->toBe($second->id);
});

it('falls back to round-robin when the lead has no routing tags', function (): void {
    $tenant = Tenant::factory()->create();

    $first = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['VIP'],
    ]);

    $second = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['Technical'],
    ]);

    Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $first->id,
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'routing_tags' => null,
        'assigned_user_id' => null,
    ]);

    $assigned = app(ContextAwareRoutingService::class)->assign($lead);

    expect($assigned)->not->toBeNull()
        ->and($assigned->id)->toBe($second->id);
});

it('ignores offline agents even when their skills tags match perfectly', function (): void {
    $tenant = Tenant::factory()->create();

    $offlinePerfect = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => false,
        'skills_tags' => ['English', 'VIP'],
    ]);

    $onlinePartial = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => true,
        'skills_tags' => ['English'],
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'routing_tags' => ['English', 'VIP'],
        'assigned_user_id' => null,
    ]);

    $assigned = app(ContextAwareRoutingService::class)->assign($lead);

    expect($assigned)->not->toBeNull()
        ->and($assigned->id)->toBe($onlinePartial->id)
        ->and($assigned->id)->not->toBe($offlinePerfect->id);
});

it('returns null when no online sales reps are available', function (): void {
    $tenant = Tenant::factory()->create();

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'is_active' => true,
        'is_online' => false,
        'skills_tags' => ['English'],
    ]);

    $lead = Lead::factory()->create([
        'tenant_id' => $tenant->id,
        'routing_tags' => ['English'],
    ]);

    expect(app(ContextAwareRoutingService::class)->assign($lead))->toBeNull();
});
