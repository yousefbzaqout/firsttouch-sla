<?php

declare(strict_types=1);

namespace App\Services\Sla;

use App\Enums\OffHoursAction;
use App\Models\TenantWorkingHour;
use App\Support\Timezones;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SlaCalculatorService
{
    /**
     * @param  Collection<int, TenantWorkingHour>  $workingHours
     */
    public function calculate(
        Carbon $start,
        int $slaMinutes,
        Collection $workingHours,
        ?string $timezone = null,
    ): Carbon {
        $appTimezone = Timezones::resolve((string) config('app.timezone', 'UTC'));
        $tz = Timezones::resolve($timezone, $appTimezone);

        // Empty schedule = always open (aligned with BusinessHoursService).
        if ($workingHours->isEmpty()) {
            return $start->copy()->addMinutes($slaMinutes)->timezone($appTimezone);
        }

        $remaining = $slaMinutes;
        $cursor = $start->copy()->timezone($tz);

        $maxIterations = 14;
        $iterations = 0;

        while ($remaining > 0 && $iterations < $maxIterations) {
            $iterations++;
            $todayShift = $this->getShiftForDay($workingHours, (int) $cursor->dayOfWeek);

            if (! $todayShift) {
                $cursor = $this->advanceToNextWorkingDay($workingHours, $cursor);

                continue;
            }

            $shiftStart = $cursor->copy()->setTimeFromTimeString((string) $todayShift->start_time);
            $shiftEnd = $cursor->copy()->setTimeFromTimeString((string) $todayShift->end_time);

            if ($cursor->lt($shiftStart)) {
                if ($todayShift->off_hours_action === OffHoursAction::FreezeSla) {
                    $cursor = $shiftStart->copy();
                }
            }

            if ($cursor->gte($shiftEnd)) {
                $cursor = $this->advanceToNextWorkingDay($workingHours, $cursor);

                continue;
            }

            if ($cursor->lt($shiftStart)) {
                $cursor = $shiftStart->copy();
            }

            $minutesLeftInShift = (int) $cursor->diffInMinutes($shiftEnd);

            if ($remaining <= $minutesLeftInShift) {
                return $cursor->copy()->addMinutes($remaining)->timezone($appTimezone);
            }

            $remaining -= $minutesLeftInShift;
            $cursor = $this->advanceToNextWorkingDay($workingHours, $cursor);
        }

        return $cursor->copy()->addMinutes($remaining)->timezone($appTimezone);
    }

    /**
     * @param  Collection<int, TenantWorkingHour>  $workingHours
     */
    private function getShiftForDay(Collection $workingHours, int $dayOfWeek): ?TenantWorkingHour
    {
        return $workingHours->firstWhere('day_of_week', $dayOfWeek);
    }

    /**
     * @param  Collection<int, TenantWorkingHour>  $workingHours
     */
    private function advanceToNextWorkingDay(Collection $workingHours, Carbon $cursor): Carbon
    {
        $next = $cursor->copy()->addDay()->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $shift = $this->getShiftForDay($workingHours, (int) $next->dayOfWeek);

            if ($shift) {
                return $next->setTimeFromTimeString((string) $shift->start_time);
            }

            $next->addDay();
        }

        return $next;
    }
}
