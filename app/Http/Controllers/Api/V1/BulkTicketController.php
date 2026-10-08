<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuditLoggerService;
use App\Services\SlaCalculatorService;
use App\Services\TicketRoutingService;
use App\Services\TicketStateMachineService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Apply one change to many tickets. Every ticket goes through the same policy and rules as a single change;
 * the ones that are refused are reported with the reason instead of failing the whole batch.
 */
class BulkTicketController extends Controller
{
    public function __construct(
        private TicketStateMachineService $stateMachine,
        private TicketRoutingService $routing,
        private SlaCalculatorService $sla,
        private AuditLoggerService $audit,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(['status', 'priority', 'assign'])],
            'status' => ['required_if:action,status', Rule::enum(TicketStatus::class)],
            'priority' => ['required_if:action,priority', Rule::enum(TicketPriority::class)],
            'agent_id' => ['nullable', 'integer'],
        ]);

        $target = ($data['action'] === 'assign' && ! empty($data['agent_id'])) ? User::findOrFail($data['agent_id']) : null;

        $tickets = Ticket::visibleTo($user)->whereIn('id', $data['ids'])->get()->keyBy('id');
        $done = [];
        $failed = [];

        foreach ($data['ids'] as $id) {
            $ticket = $tickets->get($id);
            if (! $ticket) {
                $failed[] = ['id' => $id, 'reason' => 'Ticket not found or not visible to you.'];
                continue;
            }

            try {
                match ($data['action']) {
                    'status' => $this->setStatus($ticket, $data['status'], $request),
                    'priority' => $this->setPriority($ticket, $data['priority'], $request),
                    'assign' => $this->assign($ticket, $target, $request),
                };
                $done[] = $id;
            } catch (AuthorizationException) {
                $failed[] = ['id' => $id, 'number' => $ticket->ticket_number, 'reason' => 'You are not allowed to do this on the ticket.'];
            } catch (\Throwable $e) {
                $failed[] = ['id' => $id, 'number' => $ticket->ticket_number, 'reason' => method_exists($e, 'getMessage') && $e->getMessage() !== '' ? $e->getMessage() : 'Could not be changed.'];
            }
        }

        return response()->json([
            'message' => count($done).' of '.count($data['ids']).' tickets updated.',
            'updated' => $done,
            'failed' => $failed,
        ]);
    }

    private function setStatus(Ticket $ticket, string $status, Request $request): void
    {
        $this->authorize('changeStatus', $ticket);
        $this->stateMachine->transition($ticket, TicketStatus::from($status), $request->user(), $request->ip());
    }

    private function setPriority(Ticket $ticket, string $priority, Request $request): void
    {
        $this->authorize('manage', $ticket);
        $old = $ticket->priority->value;
        $new = TicketPriority::from($priority);

        DB::transaction(function () use ($ticket, $new, $old, $request) {
            $ticket->update(['priority' => $new]);
            $this->sla->attachDeadlines($ticket->fresh(['organization', 'department']));
            $this->audit->log($ticket, 'priority_changed', 'priority', $old, $new->value, $request->user(), $request->ip());
        });
    }

    private function assign(Ticket $ticket, ?User $target, Request $request): void
    {
        $user = $request->user();
        $this->authorize('manage', $ticket);

        if ($target) {
            if (! $target->role->isStaff() || $target->role === UserRole::ADMIN) {
                abort(422, 'Tickets can only be assigned to agents or team leads.');
            }
            if ($user->role !== UserRole::ADMIN && $target->department_id !== $ticket->department_id) {
                abort(422, "The agent is not in this ticket's department.");
            }
        }

        $expected = null;
        if ($user->role === UserRole::AGENT) {
            if (! $target || $target->id !== $user->id) {
                abort(403, 'Agents can only assign tickets to themselves.');
            }
            $expected = $user->id;
        }

        $this->routing->assign($ticket, $target, $user, $request->ip(), $expected);
    }
}
