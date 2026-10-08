<?php

namespace App\Services;

use App\Events\TicketChanged;
use App\Jobs\DeliverWebhookJob;
use App\Models\Ticket;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One place that says "something happened to a ticket": it tells Slack/Teams/webhook subscribers.
 * (Realtime browser updates hook in here too.) It can never break the action that triggered it.
 */
class TicketEvents
{
    public function publish(string $event, Ticket $ticket, array $data = []): void
    {
        try {
            $this->broadcast($ticket, str_replace('ticket.', '', $event));
            $payload = $this->payload($event, $ticket, $data);

            WebhookEndpoint::where('is_active', true)->get()
                ->filter(fn (WebhookEndpoint $e) => in_array($event, $e->events ?? [], true))
                ->each(fn (WebhookEndpoint $e) => DeliverWebhookJob::dispatch($e->id, $payload, (string) Str::uuid()));
        } catch (\Throwable $e) {
            Log::warning('Publishing ticket event failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Realtime: open ticket pages and the people's lists refresh. Internal notes only reach staff.
     */
    public function broadcast(Ticket $ticket, string $kind, bool $staffOnly = false): void
    {
        try {
            $ids = array_filter([$ticket->customer_id, $ticket->assigned_agent_id]);
            event(new TicketChanged($ticket->id, $kind, $staffOnly, $staffOnly ? array_filter([$ticket->assigned_agent_id]) : $ids));
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    /** Deliberately small: no message bodies, no customer contact details. */
    public function payload(string $event, Ticket $ticket, array $data = []): array
    {
        $ticket->loadMissing('department', 'assignedAgent');

        return [
            'event' => $event,
            'occurred_at' => now()->utc()->toIso8601String(),
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->ticket_number,
                'title' => Str::limit($ticket->title, 200, '…'),
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
                'department' => $ticket->department?->name,
                'assignee' => $ticket->assignedAgent?->name,
                'url' => rtrim(config('app.frontend_url'), '/').'/staff/tickets/'.$ticket->id,
            ],
            'data' => $data,
        ];
    }
}
