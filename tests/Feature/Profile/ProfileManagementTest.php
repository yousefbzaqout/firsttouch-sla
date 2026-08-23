<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\CompanyProfilePage;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows a user to upload an avatar and update personal info and password', function (): void {
    Storage::fake('public');

    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
        'name' => 'Old Name',
        'email' => 'old@example.test',
        'password' => 'Password1!',
    ]);

    $this->actingAs($user);

    $avatar = UploadedFile::fake()->image('avatar.jpg', 200, 200);

    Livewire::test(EditProfile::class)
        ->fillForm([
            'name' => 'New Name',
            'email' => 'new@example.test',
            'telegram_chat_id' => 5178336797,
            'avatar_url' => $avatar,
            'password' => 'NewPassword1!',
            'passwordConfirmation' => 'NewPassword1!',
            'currentPassword' => 'Password1!',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->name)->toBe('New Name')
        ->and($user->email)->toBe('new@example.test')
        ->and($user->telegram_chat_id)->toBe(5178336797)
        ->and($user->avatar_url)->not->toBeNull()
        ->and(Hash::check('NewPassword1!', $user->password))->toBeTrue()
        ->and($user->hasCustomAvatar())->toBeTrue()
        ->and($user->getAvatarUrl())->toContain('/storage/');
});

it('allows a tenant owner to update company name and logo', function (): void {
    Storage::fake('public');

    $tenant = Tenant::factory()->create(['name' => 'Old Co']);
    TenantSetting::factory()->create([
        'tenant_id' => $tenant->id,
        'timezone' => 'UTC',
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $this->actingAs($owner);

    $logo = UploadedFile::fake()->image('logo.png', 300, 300);

    Livewire::test(CompanyProfilePage::class)
        ->fillForm([
            'company_name' => 'Apex Media',
            'company_logo' => [$logo],
            'business_category' => 'Marketing',
            'timezone' => 'Asia/Riyadh',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $tenant->refresh();
    $setting = TenantSetting::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

    expect($tenant->name)->toBe('Apex Media')
        ->and($tenant->business_category)->toBe('Marketing')
        ->and($tenant->logo_url)->not->toBeNull()
        ->and($tenant->hasCustomLogo())->toBeTrue()
        ->and($setting?->timezone)->toBe('Asia/Riyadh');
});

it('forbids sales reps from accessing the company profile page', function (): void {
    $tenant = Tenant::factory()->create();
    TenantSetting::factory()->create(['tenant_id' => $tenant->id]);

    $salesRep = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::SalesRep,
    ]);

    $this->actingAs($salesRep);

    expect(CompanyProfilePage::canAccess())->toBeFalse()
        ->and(CompanyProfilePage::shouldRegisterNavigation())->toBeFalse();

    $this->get(CompanyProfilePage::getUrl())->assertForbidden();
});

it('falls back gracefully when no avatar is uploaded', function (): void {
    $user = User::factory()->make([
        'name' => 'Sara Sales',
        'avatar_url' => null,
    ]);

    expect($user->hasCustomAvatar())->toBeFalse()
        ->and($user->getAvatarUrl())->toContain('ui-avatars.com')
        ->and($user->getAvatarUrl())->toContain(urlencode('Sara Sales'))
        ->and($user->getFilamentAvatarUrl())->toBe($user->getAvatarUrl());
});
