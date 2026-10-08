<?php

namespace App\Services;

use App\Enums\SlaMetricType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidTicketStateTransitionException;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\DB;

class TicketStateMachineService
{
    /**
     * Transition matrix: [from_state => [allowed_target_states]].
     */
    private const ALLOWED_TRANSITIONS = [
        'open' => ['in_progress', 'closed'],
        'in_progress' => ['pending_customer', 'resolved', 'closed'],
        'pending_customer' => ['in_progress', 'resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => [],
    ];

    /**
     * Customer-permitted moves on their own ticket: close it or reopen/reply back to in_progress.
     */
    private const CUSTOMER_TRANSITIONS = [
        'open' => ['closed'],
        'in_progress' => ['closed'],
        'pending_customer' => ['in_progress', 'closed'],
        'resolved' => ['in_progress', 'closed'],
    ];

    public function __construct(
        private SlaCalculatorService $sla,
        private AuditLoggerService $auditLogger,
    ) {}

    public function transition(Ticket $ticket, TicketStatus $newStatus, ?User $actor, ?string $ipAddress = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $newStatus, $actor, $ipAddress) {
            $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $currentStatus = $locked->status;

            if ($currentStatus === $newStatus) {
                return $ticket;
            }

            $this->assertAllowed($currentStatus, $newStatus, $actor);

            $locked->status = $newStatus;

            if ($newStatus === TicketStatus::PENDING_CUSTOMER) {
                $this->sla->pauseResolutionClock($locked);
            }

            if ($currentStatus === TicketStatus::PENDING_CUSTOMER && $newStatus === TicketStatus::IN_PROGRESS) {
                $this->sla->resumeResolutionClock($locked);
            }

            if ($newStatus === TicketStatus::RESOLVED || $newStatus === TicketStatus::CLOSED) {
                if (! $locked->resolved_at) {
                    $locked->resolved_at = now();
                }
                if ($newStatus === TicketStatus::CLOSED) {
                    $locked->closed_at = $locked->closed_at ?? now();
                }
                $this->sla->fulfill($locked, SlaMetricType::FIRST_RESPONSE);
                $this->sla->fulfill($locked, SlaMetricType::RESOLUTION);
            }

            if ($newStatus === TicketStatus::IN_PROGRESS
                && in_array($currentStatus, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true)) {
                $locked->resolved_at = null;
                $locked->closed_at = null;
                $this->sla->reopenResolutionClock($locked);
            }

            $locked->save();

            $this->auditLogger->log(
                $locked,
                'status_transition',
                'status',
                $currentStatus->value,
                $newStatus->value,
                $actor,
                $ipAddress
            );

            if ($newStatus === TicketStatus::RESOLVED && $locked->customer) {
                $locked->customer->notify(new TicketResolvedNotification($locked));
            }

            app(TicketEvents::class)->publish('ticket.status_changed', $locked, ['from' => $currentStatus->value, 'to' => $newStatus->value]);

            // Keep the caller's instance in sync.
            $ticket->setRawAttributes($locked->getAttributes(), true);

            return $ticket;
        });
    }

    private function assertAllowed(TicketStatus $from, TicketStatus $to, ?User $actor): void
    {
        $role = $actor?->role;

        // System-initiated transitions (no actor) follow the base matrix.
        $allowed = in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);

        if ($from === TicketStatus::CLOSED) {
            // Closed is locked; only an admin may reopen.
            $allowed = $role === UserRole::ADMIN;
        } elseif ($role === UserRole::CUSTOMER) {
            $allowed = $allowed && in_array($to->value, self::CUSTOMER_TRANSITIONS[$from->value] ?? [], true);
        }

        if (! $allowed) {
            throw new InvalidTicketStateTransitionException($from->value, $to->value);
        }
    }
}
