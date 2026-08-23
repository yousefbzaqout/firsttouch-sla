<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgePriority;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'knowledge_base_id',
        'content',
        'priority',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'priority' => KnowledgePriority::class,
        ];
    }

    /** @return BelongsTo<KnowledgeBase, $this> */
    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
