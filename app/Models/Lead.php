<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SafeBackedEnum;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SlaStatus;
use App\Traits\BelongsToTenant;
use Carbon\Carbon;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Carbon|null $sla_deadline
 * @property Carbon|null $sla_started_at
 * @property Carbon|null $sla_warning_sent_at
 * @property Carbon|null $sla_escalated_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $first_action_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $external_lead_id
 * @property LeadSource $source
 * @property LeadStatus $status
 * @property SlaStatus $sla_status
 * @property array<string, mixed>|null $meta_data
 * @property list<array{label: string, text: string}>|null $ai_quick_replies
 * @property list<string>|null $routing_tags
 * @property int|null $assigned_user_id
 * @property int|null $previous_assigned_user_id
 * @property string $tenant_id
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'assigned_user_id',
        'previous_assigned_user_id',
        'source',
        'external_lead_id',
        'name',
        'phone',
        'email',
        'status',
        'sla_status',
        'sla_deadline',
        'sla_started_at',
        'sla_warning_sent_at',
        'sla_escalated_at',
        'claimed_at',
        'first_action_at',
        'meta_data',
        'ai_quick_replies',
        'routing_tags',
    ];

    protected function casts(): array
    {
        return [
            'source' => SafeBackedEnum::class.':'.LeadSource::class.','.LeadSource::Manual->value,
            'status' => SafeBackedEnum::class.':'.LeadStatus::class.','.LeadStatus::New->value,
            'sla_status' => SafeBackedEnum::class.':'.SlaStatus::class.','.SlaStatus::Pending->value,
            'sla_deadline' => 'datetime',
            'sla_started_at' => 'datetime',
            'sla_warning_sent_at' => 'datetime',
            'sla_escalated_at' => 'datetime',
            'claimed_at' => 'datetime',
            'first_action_at' => 'datetime',
            'meta_data' => 'array',
            'ai_quick_replies' => 'array',
            'routing_tags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            if ($lead->external_lead_id === '') {
                $lead->external_lead_id = null;
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function previousAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_assigned_user_id');
    }
}
