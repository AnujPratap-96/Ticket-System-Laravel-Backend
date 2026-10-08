<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SlaMetricType;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\AgentCollisionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMessageRequest;
use App\Http\Resources\TicketMessageResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AgentPresenceService;
use App\Services\AttachmentService;
use App\Services\AuditLoggerService;
use App\Services\AutomationEngine;
use App\Services\SlaCalculatorService;
use App\Services\TicketNotifier;
use App\Services\TicketStateMachineService;
use Illuminate\Http\JsonResponse;
use App\Notifications\TicketReplyNotification;
use Illuminate\Support\Facades\DB;

class TicketMessageController extends Controller
{
    public function __construct(
        private AgentPresenceService $presenceService,
        private TicketStateMachineService $stateMachine,
        private AuditLoggerService $auditLogger,
        private SlaCalculatorService $sla,
        private AttachmentService $attachments,
        private TicketNotifier $notifier,
        private AutomationEngine $automation,
    ) {}

    public function store(Ticket $ticket, CreateMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('reply', $ticket);

        $isStaff = $user->role->isStaff();
        $isInternal = $request->boolean('is_internal_note');

        if ($isInternal && ! $isStaff) {
            abort(403, 'Only staff members can author internal notes.');
        }

        if ($ticket->status === TicketStatus::CLOSED && $user->role !== UserRole::ADMIN) {
            abort(422, 'This ticket is closed and can no longer be replied to.');
        }

        // Collision check: warn only if another agent holds an active "typing" draft lock.
        if (! $isInternal && $isStaff && ! $request->boolean('force_send')) {
            $collisions = $this->presenceService->getTypingCollisions($ticket->id, $user->id);
            if (! empty($collisions)) {
                throw new AgentCollisionException($collisions[0]['name'] ?? 'Another Agent');
            }
        }

        $stored = $this->attachments->resolve(
            $request->input('attachments', []),
            AttachmentService::ticketFolder($ticket)
        );

        $message = DB::transaction(function () use ($ticket, $user, $request, $isInternal, $isStaff, $stored) {
            $msg = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $user->id,
                'is_internal_note' => $isInternal,
                'body' => $request->body,
                'attachments_json' => $stored ?: null,
            ]);

            if ($isStaff && ! $isInternal) {
                if (! $ticket->first_responded_at) {
                    $ticket->first_responded_at = now();
                    $ticket->save();
                    $this->sla->fulfill($ticket, SlaMetricType::FIRST_RESPONSE);
                }

                if ($ticket->status === TicketStatus::OPEN) {
                    $this->stateMachine->transition($ticket, TicketStatus::IN_PROGRESS, $user, $request->ip());
                }
            } elseif (! $isStaff && in_array($ticket->status, [TicketStatus::PENDING_CUSTOMER, TicketStatus::RESOLVED], true)) {
                // Customer reply auto-reopens / resumes the ticket.
                $this->stateMachine->transition($ticket, TicketStatus::IN_PROGRESS, $user, $request->ip());
            }

            $this->auditLogger->log(
                $ticket,
                $isInternal ? 'internal_note_added' : 'reply_posted',
                null,
                null,
                "Message ID: {$msg->id}",
                $user,
                $request->ip()
            );

            // Email the customer about public staff replies (queued, sent after commit).
            if ($isStaff && ! $isInternal && $ticket->customer_id !== $user->id) {
                $msg->setRelation('sender', $user);
                $ticket->customer?->notify(new TicketReplyNotification($ticket, $msg));
            }

            // @mentions: staff authors only, and only teammates who may see this ticket.
            if ($isStaff && $request->filled('mentions')) {
                $eligible = User::whereIn('id', $request->input('mentions'))
                    ->whereIn('role', ['agent', 'lead', 'admin'])
                    ->where(fn ($q) => $q->where('department_id', $ticket->department_id)->orWhere('role', 'admin'))
                    ->pluck('id')->all();
                // A mentioned teammate must be able to open the ticket they were pinged about.
                $ticket->watchers()->syncWithoutDetaching($eligible);
                $this->notifier->mentioned($ticket, $eligible, $user, $request->body);
            }

            // Tell the owning agent (or department leads) that the customer wrote back.
            if (! $isStaff) {
                $this->notifier->customerReplied($ticket, $msg);
            }

            return $msg;
        });

        if ($isStaff) {
            $this->presenceService->recordPresence($ticket->id, $user, 'viewing');
        } else {
            $this->automation->fire('customer_replied', $ticket);
        }

        app(\App\Services\TicketEvents::class)->broadcast($ticket, $isInternal ? 'note' : 'message', $isInternal);

        $message->load('sender');

        return response()->json([
            'message' => $isInternal ? 'Internal note added' : 'Reply posted successfully',
            'ticket_message' => new TicketMessageResource($message),
        ], 201);
    }
}
