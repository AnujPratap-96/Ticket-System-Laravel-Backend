<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\MergeTicketRequest;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLoggerService;
use App\Services\SlaCalculatorService;
use App\Enums\SlaMetricType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketCollaborationController extends Controller
{
    public function watch(Ticket $ticket, Request $request): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $ticket->watchers()->syncWithoutDetaching([$request->user()->id]);

        return response()->json(['watching' => true]);
    }

    public function unwatch(Ticket $ticket, Request $request): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $ticket->watchers()->detach($request->user()->id);

        return response()->json(['watching' => false]);
    }

    /**
     * Staff who can be @mentioned on this ticket: its department plus admins.
     */
    public function mentionable(Ticket $ticket, Request $request): JsonResponse
    {
        $this->authorize('manage', $ticket);

        $users = User::where('is_active', true)
            ->where(fn ($q) => $q->where('department_id', $ticket->department_id)->orWhere('role', UserRole::ADMIN->value))
            ->whereIn('role', ['agent', 'lead', 'admin'])
            ->where('id', '!=', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        return response()->json(['users' => $users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value])]);
    }

    /**
     * Merge a duplicate ticket ($ticket) into $target: its conversation moves over and it is closed.
     * Lead/admin only; both tickets must belong to the same customer and be reachable by the actor.
     */
    public function merge(Ticket $ticket, MergeTicketRequest $request, AuditLoggerService $audit, SlaCalculatorService $sla): JsonResponse
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [UserRole::LEAD, UserRole::ADMIN], true), 403, 'Only leads and admins can merge tickets.');
        $this->authorize('manage', $ticket);

        $target = Ticket::findOrFail($request->target_ticket_id);
        $this->authorize('manage', $target);

        abort_if($ticket->customer_id !== $target->customer_id, 422, 'Only tickets from the same customer can be merged.');
        abort_if($ticket->status === TicketStatus::CLOSED || $target->status === TicketStatus::CLOSED, 422, 'Closed tickets cannot be merged.');
        abort_if($ticket->merged_into_id !== null || $target->merged_into_id !== null, 422, 'One of these tickets was already merged.');

        DB::transaction(function () use ($ticket, $target, $actor, $request, $audit, $sla) {
            TicketMessage::where('ticket_id', $ticket->id)->update(['ticket_id' => $target->id]);

            TicketMessage::create([
                'ticket_id' => $target->id,
                'sender_id' => $actor->id,
                'is_internal_note' => true,
                'body' => "Merged from {$ticket->ticket_number} — \"{$ticket->title}\".\n\nOriginal request:\n{$ticket->description}",
                'attachments_json' => $ticket->attachments_json,
            ]);

            // The duplicate is finished: stop its clocks and close it, pointing at the survivor.
            $sla->fulfill($ticket, SlaMetricType::FIRST_RESPONSE);
            $sla->fulfill($ticket, SlaMetricType::RESOLUTION);
            $ticket->forceFill(['status' => TicketStatus::CLOSED, 'closed_at' => now(), 'resolved_at' => $ticket->resolved_at ?? now(), 'merged_into_id' => $target->id])->save();

            $audit->log($ticket, 'merged_into', 'merged_into_id', null, $target->ticket_number, $actor, $request->ip());
            $audit->log($target, 'merged_from', 'merged_from', null, $ticket->ticket_number, $actor, $request->ip());
        });

        return response()->json([
            'message' => "{$ticket->ticket_number} merged into {$target->ticket_number}",
            'target_ticket_id' => $target->id,
        ]);
    }
}
