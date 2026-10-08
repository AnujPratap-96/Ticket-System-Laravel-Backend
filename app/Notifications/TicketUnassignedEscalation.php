<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Concerns\BrandedMail;
use App\Support\MarkdownSafe;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TicketUnassignedEscalation extends Notification
{
    use BrandedMail;

    public function __construct(public Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'unassigned',
            'title' => "Unassigned ticket {$this->ticket->ticket_number}",
            'body' => 'All agents are at capacity or unavailable. Assign it manually.',
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->branded("Unassigned ticket {$this->ticket->ticket_number}", 'emails.unassigned', [
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'number' => $this->ticket->ticket_number,
            'subject' => MarkdownSafe::escape($this->ticket->title, 120),
            'url' => rtrim(config('app.frontend_url'), '/')."/staff/tickets/{$this->ticket->id}",
        ]);
    }
}
