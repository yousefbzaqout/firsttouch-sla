<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantWorkingHour;
use App\Support\Timezones;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BusinessHoursService
{
    public function isWorkingHour(Tenant $tenant, ?Carbon $dateTime = null): bool
    {
        $timezone = $this->resolveTimezone($tenant);
        $at = ($dateTime ?? Carbon::now())->copy()->timezone($timezone);
        $workingHours = $this->workingHoursFor($tenant);

        if ($workingHours->isEmpty()) {
            return true;
        }

        $shift = $this->shiftForDay($workingHours, (int) $at->dayOfWeek);

        if ($shift === null || $this->isShiftClosed($shift)) {
            return false;
        }

        $shiftStart = $at->copy()->setTimeFromTimeString((string) $shift->start_time);
        $shiftEnd = $at->copy()->setTimeFromTimeString((string) $shift->end_time);

        return $at->gte($shiftStart) && $at->lt($shiftEnd);
    }

    public function getNextWorkingHourStart(Tenant $tenant, ?Carbon $dateTime = null): Carbon
    {
        $timezone = $this->resolveTimezone($tenant);
        $at = ($dateTime ?? Carbon::now())->copy()->timezone($timezone);
        $workingHours = $this->workingHoursFor($tenant);

        if ($workingHours->isEmpty()) {
            return ($dateTime ?? Carbon::now())->copy();
        }

        $cursor = $at->copy();

        for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
            if ($dayOffset > 0) {
                $cursor = $at->copy()->addDays($dayOffset)->startOfDay();
            }

            $shift = $this->shiftForDay($workingHours, (int) $cursor->dayOfWeek);

            if ($shift === null || $this->isShiftClosed($shift)) {
                continue;
            }

            $shiftStart = $cursor->copy()->setTimeFromTimeString((string) $shift->start_time);
            $shiftEnd = $cursor->copy()->setTimeFromTimeString((string) $shift->end_time);

            if ($dayOffset === 0) {
                if ($cursor->lt($shiftStart)) {
                    return $this->toAppTimezone($shiftStart);
                }

                if ($cursor->lt($shiftEnd)) {
                    return $this->toAppTimezone($cursor);
                }

                continue;
            }

            return $this->toAppTimezone($shiftStart);
        }

        return $this->toAppTimezone($at->copy()->addDay()->startOfDay());
    }

    private function resolveTimezone(Tenant $tenant): string
    {
        $setting = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();

        $timezone = $setting?->timezone;

        return Timezones::resolve(is_string($timezone) ? $timezone : null);
    }

    /**
     * @return Collection<int, TenantWorkingHour>
     */
    private function workingHoursFor(Tenant $tenant): Collection
    {
        return TenantWorkingHour::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get();
    }

    /**
     * @param  Collection<int, TenantWorkingHour>  $workingHours
     */
    private function shiftForDay(Collection $workingHours, int $dayOfWeek): ?TenantWorkingHour
    {
        return $workingHours->firstWhere('day_of_week', $dayOfWeek);
    }

    private function isShiftClosed(TenantWorkingHour $shift): bool
    {
        // Schema has no is_closed column; support it if present on the model attributes.
        if (! array_key_exists('is_closed', $shift->getAttributes())) {
            return false;
        }

        return (bool) $shift->getAttribute('is_closed');
    }

    private function toAppTimezone(Carbon $moment): Carbon
    {
        return $moment->copy()->timezone((string) config('app.timezone', 'UTC'));
    }
}
