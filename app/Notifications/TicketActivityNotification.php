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
 * Staff-facing event: customer replied, ticket assigned to you, SLA about to breach.
 * Delivered in-app (bell) and by email.
 */
class TicketActivityNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable, SendsWebPush;

    public const CUSTOMER_REPLIED = 'customer_replied';
    public const ASSIGNED = 'assigned';
    public const SLA_WARNING = 'sla_warning';
    public const MENTIONED = 'mentioned';
    public const LOW_RATING = 'low_rating';

    public function __construct(
        public string $kind,
        public int $ticketId,
        public string $ticketNumber,
        public string $title,
        public string $body,
    ) {
        $this->afterCommit();
    }

    public static function customerReplied(Ticket $ticket, string $excerpt): self
    {
        return new self(self::CUSTOMER_REPLIED, $ticket->id, $ticket->ticket_number,
            "Customer replied on {$ticket->ticket_number}", Str::limit($excerpt, 200));
    }

    public static function assigned(Ticket $ticket): self
    {
        return new self(self::ASSIGNED, $ticket->id, $ticket->ticket_number,
            "Ticket {$ticket->ticket_number} assigned to you", Str::limit($ticket->title, 200));
    }

    public static function lowRating(Ticket $ticket, int $rating, ?string $comment): self
    {
        return new self(self::LOW_RATING, $ticket->id, $ticket->ticket_number,
            "{$rating}-star rating on {$ticket->ticket_number}", Str::limit($comment ?: $ticket->title, 200));
    }

    public static function mentioned(Ticket $ticket, string $by, string $excerpt): self
    {
        return new self(self::MENTIONED, $ticket->id, $ticket->ticket_number,
            "{$by} mentioned you on {$ticket->ticket_number}", Str::limit($excerpt, 200));
    }

    public static function slaWarning(Ticket $ticket, string $metric, string $target): self
    {
        $label = $metric === 'first_response' ? 'First response' : 'Resolution';

        return new self(self::SLA_WARNING, $ticket->id, $ticket->ticket_number,
            "{$label} SLA due soon on {$ticket->ticket_number}", "Target: {$target} UTC. {$ticket->title}");
    }

    public function via(object $notifiable): array
    {
        return $this->withPush($notifiable, ['database', 'mail']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $staff = $notifiable->role->isStaff();
        $url = rtrim(config('app.frontend_url'), '/').($staff ? "/staff/tickets/{$this->ticketId}" : "/portal/tickets/{$this->ticketId}");

        [$heading, $lead] = match ($this->kind) {
            self::CUSTOMER_REPLIED => ['The customer replied', 'The customer wrote back on a ticket you follow.'],
            self::ASSIGNED => ['A ticket was assigned to you', 'You are now the owner of this ticket.'],
            self::SLA_WARNING => ['SLA deadline approaching', 'A service-level target on this ticket is due in about 15 minutes.'],
            self::LOW_RATING => ['A customer was unhappy', 'A customer gave a low satisfaction rating after this ticket was resolved.'],
            self::MENTIONED => ['You were mentioned', 'A teammate mentioned you in a note on this ticket.'],
            default => ['Ticket update', 'There is an update on this ticket.'],
        };

        $ticketTitle = Ticket::whereKey($this->ticketId)->value('title') ?? '';

        return $this->branded($this->title, 'emails.staff-activity', [
            'heading' => $heading,
            'lead' => $lead,
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'number' => $this->ticketNumber,
            'subject' => MarkdownSafe::escape($ticketTitle, 120),
            // For replies and mentions the body is user-written text: show it literally, never as Markdown.
            'detail' => in_array($this->kind, [self::CUSTOMER_REPLIED, self::MENTIONED, self::LOW_RATING], true) ? MarkdownSafe::escape($this->body, 500) : '',
            'url' => $url,
        ]);
    }
}
