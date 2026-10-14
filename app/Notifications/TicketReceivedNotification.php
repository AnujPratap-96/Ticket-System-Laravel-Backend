<?php

namespace App\Notifications;

use App\Enums\SlaMetricType;
use App\Models\Ticket;
use App\Notifications\Concerns\BrandedMail;
use App\Support\MarkdownSafe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "We got your request": sent to the customer when a ticket is opened (portal, email or API), with the ticket
 * number to quote and when to expect the first answer. Email only: it must not clutter the in-app bell.
 */
class TicketReceivedNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable;

    public function __construct(public Ticket $ticket)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->forNotifiable($notifiable);
        $t = $this->ticket;
        $first = $t->slaDeadlines()->where('metric_type', SlaMetricType::FIRST_RESPONSE->value)->first();

        // [TICK-...] in the subject is how an emailed reply is matched back to the ticket.
        return $this->branded("[{$t->ticket_number}] We received your request: {$t->title}", 'emails.ticket-received', [
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'number' => $t->ticket_number,
            'subject' => MarkdownSafe::escape($t->title, 120),
            'department' => MarkdownSafe::escape((string) $t->department?->name, 80),
            'replyBy' => $first?->target_deadline?->copy()->utc()->format('j M Y, H:i').' UTC',
            'url' => $t->tenant?->url("/portal/tickets/{$t->id}") ?? rtrim(config('app.frontend_url'), '/')."/portal/tickets/{$t->id}",
            'canReplyByEmail' => (bool) $t->tenant?->inbound_secret,
        ]);
    }
}
