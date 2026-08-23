<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Auth\Register;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('registers a tenant with settings, working hours, and owner user via filament', function (): void {
    Livewire::test(Register::class)
        ->fillForm([
            'company_name' => 'Acme Corp',
            'name' => 'Jane Owner',
            'email' => 'jane@acme.test',
            'password' => 'Password1!',
            'passwordConfirmation' => 'Password1!',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin');

    $user = User::query()->where('email', 'jane@acme.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Owner')
        ->and($user->role)->toBe(UserRole::Owner)
        ->and($user->tenant_id)->not->toBeNull()
        ->and(Hash::check('Password1!', $user->password))->toBeTrue();

    $tenant = Tenant::query()->find($user->tenant_id);

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('Acme Corp')
        ->and($tenant->is_active)->toBeTrue();

    $setting = TenantSetting::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->credits_balance)->toBe(50)
        ->and($setting->sla_timeout_minutes)->toBe(15);

    expect(
        TenantWorkingHour::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->count()
    )->toBe(7);

    $this->assertAuthenticatedAs($user);
});

it('dispatches a password reset notification with a filament reset url', function (): void {
    Notification::fake();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'reset@acme.test',
    ]);

    $status = Password::broker()->sendResetLink(['email' => $user->email]);

    expect($status)->toBe(Password::RESET_LINK_SENT);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $user->getFilamentResetPasswordUrl($notification->token);

        expect($url)->toContain('/admin/password-reset/reset')
            ->and($url)->toContain(urlencode($user->email));

        return true;
    });
});

it('registers a tenant via the api and returns a sanctum bearer token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', [
        'company_name' => 'API Tenant',
        'name' => 'Api Owner',
        'email' => 'api-owner@acme.test',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'token',
            'token_type',
            'user' => ['id', 'name', 'email', 'tenant_id', 'role'],
        ])
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'api-owner@acme.test')
        ->assertJsonPath('user.role', UserRole::Owner->value);

    $user = User::query()->where('email', 'api-owner@acme.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->tenant_id)->not->toBeNull();

    $tenant = Tenant::query()->find($user->tenant_id);

    expect($tenant)->not->toBeNull()
        ->and($tenant->name)->toBe('API Tenant');

    expect(
        TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('credits_balance')
    )->toBe(50);

    expect(
        TenantWorkingHour::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count()
    )->toBe(7);

    expect($user->tokens)->toHaveCount(1);
});

it('sends a forgot-password email via the api endpoint', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'forgot@acme.test']);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'forgot@acme.test',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('resets a password via the api endpoint', function (): void {
    $user = User::factory()->create([
        'email' => 'reset-api@acme.test',
        'password' => 'OldPassword1!',
    ]);

    $token = Password::broker()->createToken($user);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset-api@acme.test',
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message']);

    expect(Hash::check('NewPassword1!', $user->fresh()->password))->toBeTrue();
});
