<?php

declare(strict_types=1);

use App\Jobs\DailyFreeCreditGrantJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sla:check-breaches')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('sla:check-escalation-buffers')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::job(new DailyFreeCreditGrantJob)
    ->dailyAt('00:00')
    ->withoutOverlapping();
