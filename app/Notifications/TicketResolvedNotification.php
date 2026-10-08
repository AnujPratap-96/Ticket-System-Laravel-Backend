<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\BrandedMail;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\MarkdownSafe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the customer their ticket was resolved and asks for a rating.
 */
class TicketResolvedNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable, SendsWebPush;

    public function __construct(public Ticket $ticket)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->withPush($notifiable, ['mail', 'database']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'resolved',
            'title' => "{$this->ticket->ticket_number} was resolved",
            'body' => 'How did we do? Rate your support experience.',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->branded("[{$this->ticket->ticket_number}] Your ticket was resolved", 'emails.ticket-resolved', [
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'number' => $this->ticket->ticket_number,
            'subject' => MarkdownSafe::escape($this->ticket->title, 120),
            'url' => rtrim(config('app.frontend_url'), '/')."/portal/tickets/{$this->ticket->id}",
        ]);
    }
}
