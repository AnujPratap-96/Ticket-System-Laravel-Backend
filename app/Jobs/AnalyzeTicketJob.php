<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Ai\TicketAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeTicketJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $ticketId)
    {
        $this->afterCommit();
    }

    public function handle(TicketAnalyzer $analyzer): void
    {
        $ticket = Ticket::withoutGlobalScopes()->find($this->ticketId);
        if (! $ticket) {
            return;
        }

        // the AI settings, departments and budget are this ticket's workspace's
        \App\Tenancy\TenantContext::run($ticket->tenant, fn () => $this->analyze($ticket, $analyzer));
    }

    private function analyze(Ticket $ticket, TicketAnalyzer $analyzer): void
    {

        $r = $analyzer->analyze($ticket);
        $ticket->forceFill([
            'sentiment' => $r['sentiment'],
            'is_ai_urgent' => $r['urgent'],
            // Never overwrite a decision a human already made.
            'ai_triage' => ($ticket->ai_triage['status'] ?? 'suggested') === 'suggested' ? $r['triage'] : $ticket->ai_triage,
        ])->saveQuietly();
    }
}
