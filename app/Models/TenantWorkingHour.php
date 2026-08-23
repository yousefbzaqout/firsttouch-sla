<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OffHoursAction;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property OffHoursAction $off_hours_action
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 */
class TenantWorkingHour extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'day_of_week',
        'start_time',
        'end_time',
        'off_hours_action',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'off_hours_action' => OffHoursAction::class,
        ];
    }
}
