<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\Concerns\BrandedMail;
use App\Notifications\Concerns\SendsWebPush;
use App\Support\MarkdownSafe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Emails the customer when staff post a public reply on their ticket.
 */
class TicketReplyNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable, SendsWebPush;

    public function __construct(public Ticket $ticket, public TicketMessage $message)
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
            'kind' => 'staff_replied',
            'title' => "New reply on {$this->ticket->ticket_number}",
            'body' => Str::limit($this->message->body, 200),
            'ticket_id' => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/')."/portal/tickets/{$this->ticket->id}";

        // The [TICK-...] tag in the subject is how an emailed reply is matched back to the ticket.
        return $this->branded("[{$this->ticket->ticket_number}] New reply: {$this->ticket->title}", 'emails.ticket-reply', [
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'agent' => MarkdownSafe::escape($this->message->sender?->name ?? 'Our support team', 80),
            'number' => $this->ticket->ticket_number,
            'subject' => MarkdownSafe::escape($this->ticket->title, 120),
            // Formatting is kept (bold, lists, links), raw HTML never is; see RichText.
            'excerpt' => \App\Support\RichText::toHtml(\Illuminate\Support\Str::limit($this->message->body, 3000, '…')),
            'url' => $url,
            'canReplyByEmail' => (bool) config('services.inbound_email.secret'),
        ]);
    }
}
