<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Support\Collection;

/**
 * Decides who hears about what. Controllers/jobs call this instead of building recipients.
 */
class TicketNotifier
{
    public function __construct(private TicketEvents $events) {}

    public function customerReplied(Ticket $ticket, TicketMessage $message): void
    {
        $this->events->publish('ticket.customer_replied', $ticket);
        $this->send($this->staffFor($ticket), TicketActivityNotification::customerReplied($ticket, $message->body));
    }

    public function assigned(Ticket $ticket, User $agent, ?User $actor = null): void
    {
        $this->events->publish('ticket.assigned', $ticket);
        if ($actor && $actor->id === $agent->id) {
            return;
        }

        $agent->notify(TicketActivityNotification::assigned($ticket));
    }

    public function slaWarning(TicketSlaDeadline $deadline): void
    {
        $ticket = $deadline->ticket;
        $this->events->publish('sla.warning', $ticket, ['metric' => $deadline->metric_type->value, 'due_at' => $deadline->target_deadline->toIso8601String()]);

        $this->send(
            $this->staffFor($ticket),
            TicketActivityNotification::slaWarning($ticket, $deadline->metric_type->value, $deadline->target_deadline->toDateTimeString())
        );
    }

    /**
     * A 1-2 star rating goes to the department's leads and the admins so it can be followed up.
     */
    public function lowRating(Ticket $ticket, int $rating, ?string $comment): void
    {
        $this->events->publish('rating.low', $ticket, ['rating' => $rating]);
        $recipients = User::where('is_active', true)->where(fn ($q) => $q
            ->where('role', UserRole::ADMIN->value)
            ->orWhere(fn ($w) => $w->where('role', UserRole::LEAD->value)->where('department_id', $ticket->department_id)))->get();

        $this->send($recipients, TicketActivityNotification::lowRating($ticket, $rating, $comment));
    }

    /**
     * The owner if assigned, otherwise the department's leads.
     */
    private function staffFor(Ticket $ticket): Collection
    {
        $owners = ($ticket->assigned_agent_id && ($agent = User::find($ticket->assigned_agent_id)))
            ? collect([$agent])
            : User::where('role', UserRole::LEAD->value)->where('department_id', $ticket->department_id)->get();

        // Watchers follow every update on the ticket.
        return $owners->concat($ticket->watchers()->where('is_active', true)->get());
    }

    /**
     * @param  array<int>  $userIds  already validated as eligible staff
     */
    public function mentioned(Ticket $ticket, array $userIds, User $author, string $excerpt): void
    {
        $users = User::whereIn('id', $userIds)->where('id', '!=', $author->id)->where('is_active', true)->get();

        $this->send($users, TicketActivityNotification::mentioned($ticket, $author->name, $excerpt));
    }

    private function send(Collection $users, TicketActivityNotification $notification): void
    {
        foreach ($users->unique('id') as $user) {
            $user->notify(clone $notification);
        }
    }
}
