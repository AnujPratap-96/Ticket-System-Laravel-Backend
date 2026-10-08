<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\AgentWorkloadExceededException;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketUnassignedEscalation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class TicketRoutingService
{
    public const ACTIVE_STATUSES = [TicketStatus::OPEN, TicketStatus::IN_PROGRESS];

    public function __construct(private AuditLoggerService $auditLogger, private TicketNotifier $notifier) {}

    /**
     * Pick the least-loaded available agent in the ticket's department and assign atomically.
     * Ranking: lowest active/max ratio, then least recently assigned (round-robin tie breaker).
     * If everybody is at capacity the ticket stays unassigned and team leads are notified.
     */
    public function autoRoute(Ticket $ticket): ?User
    {
        $chosen = DB::transaction(function () use ($ticket) {
            // Lock the department's candidate rows so concurrent routing serialises.
            $candidates = User::where('department_id', $ticket->department_id)
                ->whereIn('role', [UserRole::AGENT->value, UserRole::LEAD->value])
                ->where('is_available_for_routing', true)
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            $counts = Ticket::whereIn('assigned_agent_id', $candidates->pluck('id'))
                ->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_STATUSES))
                ->select('assigned_agent_id', DB::raw('COUNT(*) as c'))
                ->groupBy('assigned_agent_id')
                ->pluck('c', 'assigned_agent_id');

            $eligible = $candidates->filter(
                fn (User $a) => (int) ($counts[$a->id] ?? 0) < $a->max_active_tickets
            );

            if ($eligible->isEmpty()) {
                return null;
            }

            $best = $eligible->sortBy([
                fn (User $a, User $b) => $this->ratio($a, $counts) <=> $this->ratio($b, $counts),
                fn (User $a, User $b) => ($a->last_assigned_at?->timestamp ?? 0) <=> ($b->last_assigned_at?->timestamp ?? 0),
                fn (User $a, User $b) => $a->id <=> $b->id,
            ])->first();

            $ticket->update(['assigned_agent_id' => $best->id]);
            $best->update(['last_assigned_at' => now()]);

            return $best;
        });

        if ($chosen) {
            $this->notifier->assigned($ticket, $chosen);
        } else {
            $this->escalateToLeads($ticket);
        }

        return $chosen;
    }

    /**
     * Manual assignment with row locks; throws if the target is at capacity.
     * Returns false when another agent claimed the ticket first (caller maps to 409).
     */
    public function assign(Ticket $ticket, ?User $target, User $actor, ?string $ip, ?int $expectedAgentId = null): Ticket
    {
        $locked = $this->assignLocked($ticket, $target, $actor, $ip, $expectedAgentId);
        $this->notifyAssigned($locked, $target, $actor);

        return $locked;
    }

    private function assignLocked(Ticket $ticket, ?User $target, User $actor, ?string $ip, ?int $expectedAgentId): Ticket
    {
        return DB::transaction(function () use ($ticket, $target, $actor, $ip, $expectedAgentId) {
            $locked = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            // "Assign to me" race: first writer wins, the second sees the new owner.
            if ($expectedAgentId !== null
                && $locked->assigned_agent_id !== null
                && (int) $locked->assigned_agent_id !== $expectedAgentId) {
                abort(409, 'Ticket already claimed by '.($locked->assignedAgent?->name ?? 'another agent').'.');
            }

            if ($target) {
                $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $active = $target->assignedTickets()
                    ->whereIn('status', array_map(fn ($s) => $s->value, self::ACTIVE_STATUSES))
                    ->where('id', '!=', $locked->id)
                    ->count();

                if ($active >= $target->max_active_tickets) {
                    throw new AgentWorkloadExceededException($target->name, $active, $target->max_active_tickets);
                }

                $target->update(['last_assigned_at' => now()]);
            }

            $oldName = $locked->assignedAgent?->name;
            $locked->update(['assigned_agent_id' => $target?->id]);

            $this->auditLogger->log(
                $locked,
                'agent_reassigned',
                'assigned_agent_id',
                $oldName,
                $target?->name ?? 'Unassigned',
                $actor,
                $ip
            );

            return $locked;
        });
    }

    private function notifyAssigned(Ticket $ticket, ?User $target, User $actor): void
    {
        if ($target) {
            $this->notifier->assigned($ticket, $target, $actor);
        }
    }

    private function ratio(User $agent, $counts): float
    {
        return ((int) ($counts[$agent->id] ?? 0)) / max(1, $agent->max_active_tickets);
    }

    private function escalateToLeads(Ticket $ticket): void
    {
        $leads = User::where('role', UserRole::LEAD->value)
            ->where('department_id', $ticket->department_id)
            ->get();

        $this->auditLogger->log($ticket, 'escalated_unassigned', 'assigned_agent_id', null, 'Unassigned: all agents at capacity or unavailable');

        if ($leads->isNotEmpty()) {
            Notification::send($leads, new TicketUnassignedEscalation($ticket));
        }
    }
}
