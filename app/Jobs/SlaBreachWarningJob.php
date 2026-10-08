<?php

namespace App\Jobs;

use App\Models\TicketSlaDeadline;
use App\Services\AuditLoggerService;
use App\Services\SlaCalculatorService;
use App\Services\TicketNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fires ~15 minutes before an SLA target. A no-op if the deadline was fulfilled,
 * paused, or rescheduled since the job was queued.
 */
class SlaBreachWarningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $deadlineId,
        public string $expectedTarget
    ) {}

    public function handle(AuditLoggerService $audit, TicketNotifier $notifier): void
    {
        $deadline = TicketSlaDeadline::with('ticket')->find($this->deadlineId);

        if (! $deadline
            || $deadline->is_fulfilled
            || $deadline->paused_at
            || $deadline->warning_sent_at
            || $deadline->target_deadline->toISOString() !== $this->expectedTarget
            || now()->lt($deadline->target_deadline->copy()->subMinutes(SlaCalculatorService::WARNING_LEAD_MINUTES))) {
            return;
        }

        $deadline->update(['warning_sent_at' => now()]);

        $audit->log(
            $deadline->ticket,
            'sla_breach_warning',
            $deadline->metric_type->value,
            null,
            $deadline->target_deadline->toISOString()
        );

        $notifier->slaWarning($deadline);

        Log::warning('SLA breach imminent', [
            'ticket_id' => $deadline->ticket_id,
            'metric' => $deadline->metric_type->value,
            'target' => $deadline->target_deadline->toISOString(),
        ]);
    }
}
