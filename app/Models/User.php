<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPassword as ResetPasswordNotification;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;

/**
 * @property UserRole $role
 * @property bool $is_active
 * @property bool $is_online
 * @property list<string>|null $skills_tags
 * @property string|null $tenant_id
 * @property string|null $avatar_url
 */
class User extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'tenant_id',
        'role',
        'telegram_chat_id',
        'is_active',
        'is_online',
        'skills_tags',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function canAccessHorizon(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->role) {
            UserRole::SuperAdmin, UserRole::Owner, UserRole::Admin => true,
            default => false,
        };
    }

    public function hasRole(UserRole|string $role): bool
    {
        $expected = $role instanceof UserRole ? $role : UserRole::tryFrom($role);

        return $expected !== null && $this->role === $expected;
    }

    public function isSalesRep(): bool
    {
        return $this->role === UserRole::SalesRep;
    }

    public function canManageTenantSettings(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($this->role, [UserRole::Owner, UserRole::Admin, UserRole::SuperAdmin], true);
    }

    public function canManageTeam(): bool
    {
        return $this->canManageTenantSettings();
    }

    public function canViewAllTenantLeads(): bool
    {
        return $this->canManageTenantSettings();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->getAvatarUrl();
    }

    public function getAvatarUrl(): string
    {
        if (filled($this->avatar_url)) {
            return Storage::disk('public')->url($this->avatar_url);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=0A2540&color=fff';
    }

    public function hasCustomAvatar(): bool
    {
        return filled($this->avatar_url);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function getFilamentResetPasswordUrl(#[SensitiveParameter] string $token): string
    {
        return Filament::getResetPasswordUrl($token, $this);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'is_online' => 'boolean',
            'skills_tags' => 'array',
            'telegram_chat_id' => 'integer',
        ];
    }
}
