<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows super admins, owners, and admins to view horizon', function (UserRole $role): void {
    $user = User::factory()->make([
        'role' => $role,
        'is_active' => true,
    ]);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
})->with([
    UserRole::SuperAdmin,
    UserRole::Owner,
    UserRole::Admin,
]);

it('denies sales reps and inactive users from viewing horizon', function (): void {
    $salesRep = User::factory()->make([
        'role' => UserRole::SalesRep,
        'is_active' => true,
    ]);

    $inactiveOwner = User::factory()->make([
        'role' => UserRole::Owner,
        'is_active' => false,
    ]);

    expect(Gate::forUser($salesRep)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($inactiveOwner)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('configures horizon supervisors for default, high, and notifications queues', function (): void {
    $defaults = config('horizon.defaults.supervisor-1');

    expect($defaults['balance'])->toBe('auto')
        ->and($defaults['minProcesses'])->toBe(1)
        ->and($defaults['maxProcesses'])->toBe(10)
        ->and($defaults['queue'])->toBe(['default', 'high', 'notifications']);
});
