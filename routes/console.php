<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Runs in-process (no child PHP process), so it works the same from `schedule:work`,
// `schedule:run` (Render cron) and the /internal/tick endpoint called from a web request.
// Expired logins, and logins nobody used for 30 days, are removed so the sessions list stays meaningful.
Schedule::call(function () {
    Artisan::call('sanctum:prune-expired', ['--hours' => 24]);
    \Laravel\Sanctum\PersonalAccessToken::where(fn ($q) => $q->whereNotNull('last_used_at')->where('last_used_at', '<', now()->subDays(30)))
        ->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('created_at', '<', now()->subDays(30)))->delete();
})->name('prune-tokens')->daily();

Schedule::call(fn () => Artisan::call('automation:run-idle'))
    ->name('automation-run-idle')
    ->everyTenMinutes()
    ->withoutOverlapping(10);

Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put(\App\Services\OpsHealth::HEARTBEAT, now(), now()->addDay());
    Artisan::call('sla:check-breaches');
})
    ->name('sla-check-breaches')
    ->everyMinute()
    ->withoutOverlapping(5);

// Nightly database backup (14 days kept). Needs mysqldump / pg_dump on the host; see README "Backups".
Schedule::call(fn () => Artisan::call('db:backup'))->name('db-backup')->dailyAt('02:30')->withoutOverlapping(30);

Schedule::call(fn () => Artisan::call('ops:health', ['--alert' => true]))->name('ops-health')->everyFifteenMinutes();

// Monday-morning summary email to each workspace's admins.
Schedule::call(fn () => Artisan::call('reports:weekly'))->name('weekly-summary')->weeklyOn(1, '07:00');
