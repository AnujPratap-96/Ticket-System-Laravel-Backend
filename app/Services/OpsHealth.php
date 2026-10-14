<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Is the machinery behind the app (scheduler, queue, backups, database) actually running? */
class OpsHealth
{
    public const HEARTBEAT = 'ops:scheduler-heartbeat';

    public function report(): array
    {
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = ['ok' => true];
        } catch (\Throwable $e) {
            $checks['database'] = ['ok' => false, 'detail' => 'database unreachable'];
        }

        $beat = Cache::get(self::HEARTBEAT);
        $age = $beat ? now()->diffInMinutes($beat, true) : null;
        $checks['scheduler'] = ['ok' => $age !== null && $age <= 5, 'last_run_minutes_ago' => $age];

        $failed = \Illuminate\Support\Facades\Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $checks['queue'] = ['ok' => $failed < 10, 'failed_jobs_24h' => $failed];

        $disk = Storage::disk(config('backup.disk', 'local'));
        $last = collect($disk->files('backups'))->map(fn ($f) => $disk->lastModified($f))->max();
        $backupAge = $last ? (int) floor((now()->getTimestamp() - $last) / 3600) : null;
        $checks['backup'] = ['ok' => $backupAge !== null && $backupAge <= 30, 'last_backup_hours_ago' => $backupAge];

        return ['ok' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks, 'checked_at' => now()->toISOString()];
    }
}
