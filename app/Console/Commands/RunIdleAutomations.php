<?php

namespace App\Console\Commands;

use App\Services\AutomationEngine;
use Illuminate\Console\Command;

class RunIdleAutomations extends Command
{
    protected $signature = 'automation:run-idle';

    protected $description = 'Apply "ticket has been idle for N hours" automation rules';

    public function handle(AutomationEngine $engine): int
    {
        // each workspace has its own rules and tickets
        $changed = 0;
        \App\Tenancy\TenantContext::eachTenant(function () use ($engine, &$changed) {
            $changed += $engine->runIdle();
        });
        $this->info('Tickets changed: '.$changed);

        return self::SUCCESS;
    }
}
