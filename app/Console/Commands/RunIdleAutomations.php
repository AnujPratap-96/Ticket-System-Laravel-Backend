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
        $this->info('Tickets changed: '.$engine->runIdle());

        return self::SUCCESS;
    }
}
