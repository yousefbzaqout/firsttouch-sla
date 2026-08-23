<?php

declare(strict_types=1);

use App\Enums\OffHoursAction;
use App\Models\TenantWorkingHour;
use App\Services\Sla\SlaCalculatorService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

it('calculates deadline correctly during working hours', function (): void {
    $service = new SlaCalculatorService;

    $workingHours = new Collection([
        new TenantWorkingHour([
            'day_of_week' => 3, // Wednesday
            'start_time' => '09:00',
            'end_time' => '17:00',
            'off_hours_action' => OffHoursAction::FreezeSla,
        ]),
    ]);

    $start = Carbon::create(2026, 8, 19, 10, 0, 0); // Wed 10:00
    $deadline = $service->calculate($start, 30, $workingHours);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-08-19 10:30');
});

it('rolls over deadline to next working day when lead arrives off-hours', function (): void {
    $service = new SlaCalculatorService;

    $workingHours = new Collection([
        new TenantWorkingHour([
            'day_of_week' => 4, // Thursday
            'start_time' => '09:00',
            'end_time' => '17:00',
            'off_hours_action' => OffHoursAction::FreezeSla,
        ]),
    ]);

    // Wednesday 20:00 - no shift on Wednesday, next is Thursday
    $start = Carbon::create(2026, 8, 19, 20, 0, 0);
    $deadline = $service->calculate($start, 30, $workingHours);

    expect($deadline->format('Y-m-d H:i'))->toBe('2026-08-20 09:30');
});

it('rolls over when SLA spans across end of shift', function (): void {
    $service = new SlaCalculatorService;

    $workingHours = new Collection([
        new TenantWorkingHour([
            'day_of_week' => 3, // Wednesday
            'start_time' => '09:00',
            'end_time' => '17:00',
            'off_hours_action' => OffHoursAction::FreezeSla,
        ]),
        new TenantWorkingHour([
            'day_of_week' => 4, // Thursday
            'start_time' => '09:00',
            'end_time' => '17:00',
            'off_hours_action' => OffHoursAction::FreezeSla,
        ]),
    ]);

    $start = Carbon::create(2026, 8, 19, 16, 30, 0); // Wed 16:30, 30 min left
    $deadline = $service->calculate($start, 60, $workingHours); // need 60 min

    // 30 min Wed + 30 min Thu = Thu 09:30
    expect($deadline->format('Y-m-d H:i'))->toBe('2026-08-20 09:30');
});
