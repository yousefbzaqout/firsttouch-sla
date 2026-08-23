<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $name
 * @property string|null $logo_url
 * @property string|null $business_category
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'domain',
        'logo_url',
        'business_category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getLogoUrl(): string
    {
        if (filled($this->logo_url)) {
            return Storage::disk('public')->url($this->logo_url);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=1A365D&color=fff';
    }

    public function hasCustomLogo(): bool
    {
        return filled($this->logo_url);
    }

    /** @return HasOne<TenantSetting, $this> */
    public function setting(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    /** @return HasMany<TenantWorkingHour, $this> */
    public function workingHours(): HasMany
    {
        return $this->hasMany(TenantWorkingHour::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
