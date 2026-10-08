<?php

namespace App\Console\Commands;

use App\Models\TicketSlaDeadline;
use App\Services\AuditLoggerService;
use Illuminate\Console\Command;

class CheckSlaBreaches extends Command
{
    protected $signature = 'sla:check-breaches';

    protected $description = 'Mark unfulfilled, unpaused SLA deadlines that have passed as breached';

    public function handle(AuditLoggerService $audit): int
    {
        $count = 0;

        TicketSlaDeadline::with('ticket')
            ->where('is_fulfilled', false)
            ->where('is_breached', false)
            ->whereNull('paused_at')
            ->where('target_deadline', '<', now())
            ->each(function (TicketSlaDeadline $deadline) use ($audit, &$count) {
                $deadline->update([
                    'is_breached' => true,
                    'breached_at' => $deadline->target_deadline,
                ]);

                $audit->log(
                    $deadline->ticket,
                    'sla_breached',
                    $deadline->metric_type->value,
                    null,
                    $deadline->target_deadline->toISOString()
                );
                app(\App\Services\TicketEvents::class)->publish('sla.breached', $deadline->ticket, ['metric' => $deadline->metric_type->value]);
                $count++;
            });

        $this->info("Marked {$count} deadline(s) as breached.");

        return self::SUCCESS;
    }
}
