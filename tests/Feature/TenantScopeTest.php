<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('does not infinite-loop when resolving the authenticated user', function (): void {
    $user = User::factory()->create();

    Auth::login($user);

    $start = microtime(true);

    expect(Auth::user()?->id)->toBe($user->id)
        ->and(microtime(true) - $start)->toBeLessThan(1.0);
});
