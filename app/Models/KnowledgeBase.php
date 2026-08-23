<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeType;
use App\Traits\BelongsToTenant;
use Database\Factories\KnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property KnowledgeType $type
 * @property bool $is_active
 * @property string $tenant_id
 */
class KnowledgeBase extends Model
{
    /** @use HasFactory<KnowledgeBaseFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'title',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => KnowledgeType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<KnowledgeChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
