<?php

namespace App\Console\Commands;

use App\Services\OpsHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OpsHealthCheck extends Command
{
    protected $signature = 'ops:health {--alert : post to OPS_ALERT_WEBHOOK when something is wrong}';
    protected $description = 'Check scheduler, queue, database and backups';

    public function handle(OpsHealth $health): int
    {
        $r = $health->report();
        $bad = collect($r['checks'])->reject(fn ($c) => $c['ok'])->keys();
        $this->line(json_encode($r, JSON_PRETTY_PRINT));

        if ($bad->isNotEmpty() && $this->option('alert') && ($hook = config('services.ops_alert_webhook'))) {
            Http::timeout(5)->post($hook, ['text' => '⚠️ DeskFlow health check failing: '.$bad->implode(', ')]);
        }

        return $bad->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
