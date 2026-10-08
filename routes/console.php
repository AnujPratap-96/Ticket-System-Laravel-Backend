<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Runs in-process (no child PHP process), so it works the same from `schedule:work`,
// `schedule:run` (Render cron) and the /internal/tick endpoint called from a web request.
Schedule::call(fn () => Artisan::call('automation:run-idle'))
    ->name('automation-run-idle')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

Schedule::call(fn () => Artisan::call('sla:check-breaches'))
    ->name('sla-check-breaches')
    ->everyMinute()
    ->withoutOverlapping(5);
