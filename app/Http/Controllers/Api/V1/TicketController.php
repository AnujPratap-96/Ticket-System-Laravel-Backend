<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignAgentRequest;
use App\Http\Requests\CreateTicketRequest;
use App\Http\Requests\TransitionStatusRequest;
use App\Http\Requests\UpdatePriorityRequest;
use App\Http\Resources\TicketAuditResource;
use App\Http\Resources\TicketResource;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentPresenceService;
use App\Services\AttachmentService;
use App\Services\AuditLoggerService;
use App\Services\SlaCalculatorService;
use App\Services\TicketCreationService;
use App\Services\TicketRoutingService;
use App\Services\TicketStateMachineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    /** The list only shows these; the other relations are for the detail page. */
    private const LIST_RELATIONS = ['customer', 'assignedAgent', 'department', 'slaDeadlines'];

    private const RELATIONS = ['organization', 'customer', 'assignedAgent', 'department', 'slaDeadlines', 'tags', 'rating', 'watchers', 'mergedInto'];

    public function __construct(
        private SlaCalculatorService $slaCalculator,
        private TicketRoutingService $routingService,
        private TicketStateMachineService $stateMachine,
        private AgentPresenceService $presenceService,
        private AuditLoggerService $auditLogger,
        private AttachmentService $attachments,
        private TicketCreationService $creator,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:open,in_progress,pending_customer,resolved,closed'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'department_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:30'],
            'customer_id' => ['nullable', 'integer'],
            'scope' => ['nullable', 'in:mine,company'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = Ticket::with(self::LIST_RELATIONS);

        // Role-based visibility (the same rules as TicketPolicy)
        $query->visibleTo($user);

        // Company admins can switch between their own tickets and the whole organization's.
        if ($user->role === UserRole::CUSTOMER && $user->is_org_admin && $request->query('scope') === 'mine') {
            $query->where('customer_id', $user->id);
        }

        if ($user->role->isStaff() && $request->query('assigned_to') === 'me') {
            $query->where('assigned_agent_id', $user->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->query('department_id'));
        }
        if ($user->role->isStaff() && $request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->query('customer_id'));
        }
        if ($user->role->isStaff() && $request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('name', $request->query('tag')));
        }
        if ($request->filled('search')) {
            $search = addcslashes($request->query('search'), '%_\\');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        return TicketResource::collection(
            $query->orderBy('created_at', 'desc')->paginate((int) $request->query('per_page', 15))
        );
    }

    public function store(CreateTicketRequest $request): JsonResponse
    {
        $user = $request->user();

        $stored = $this->attachments->resolve(
            $request->input('attachments', []),
            AttachmentService::pendingFolder($user)
        );

        $ticket = $this->creator->create(
            $user,
            array_merge($request->safe()->except('attachments'), ['attachments' => $stored, 'custom_fields' => $request->input('custom_fields', [])]),
            $request->ip(),
            $user->role->isStaff()
        );

        $ticket->load(self::RELATIONS);

        return response()->json([
            'message' => 'Ticket created successfully',
            'ticket' => new TicketResource($ticket),
        ], 201);
    }

    public function show(Ticket $ticket, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $ticket);

        $relations = [...self::RELATIONS, 'messages.sender'];
        if ($user->can('viewAudit', $ticket)) {
            $relations[] = 'audits.actor';
        }
        $ticket->load($relations);

        $activeCollisions = $user->role->isStaff()
            ? $this->presenceService->getActiveCollisions($ticket->id, $user->id)
            : [];

        return response()->json([
            'ticket' => new TicketResource($ticket),
            'active_collisions' => $activeCollisions,
        ]);
    }

    public function audits(Ticket $ticket): JsonResponse
    {
        $this->authorize('viewAudit', $ticket);

        return response()->json([
            'audits' => TicketAuditResource::collection($ticket->audits()->with('actor')->get()),
        ]);
    }

    public function updateStatus(Ticket $ticket, TransitionStatusRequest $request): JsonResponse
    {
        $this->authorize('changeStatus', $ticket);

        $this->stateMachine->transition(
            $ticket,
            TicketStatus::from($request->status),
            $request->user(),
            $request->ip()
        );

        $ticket->load(self::RELATIONS);

        return response()->json([
            'message' => 'Ticket status updated successfully',
            'ticket' => new TicketResource($ticket),
        ]);
    }

    public function assignAgent(Ticket $ticket, AssignAgentRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('manage', $ticket);

        $target = $request->agent_id ? User::findOrFail($request->agent_id) : null;

        if ($target) {
            if (! $target->role->isStaff() || $target->role === UserRole::ADMIN) {
                abort(422, 'Tickets can only be assigned to agents or team leads.');
            }
            if ($user->role !== UserRole::ADMIN && $target->department_id !== $ticket->department_id) {
                abort(422, 'Target agent is not in this ticket\'s department.');
            }
        }

        $expectedAgentId = null;
        if ($user->role === UserRole::AGENT) {
            // Agents may only claim for themselves, and never steal an owned ticket.
            if (! $target || $target->id !== $user->id) {
                abort(403, 'Agents can only assign tickets to themselves.');
            }
            $expectedAgentId = $user->id;
        }

        $ticket = $this->routingService->assign($ticket, $target, $user, $request->ip(), $expectedAgentId);
        $ticket->load(self::RELATIONS);

        return response()->json([
            'message' => 'Ticket assigned successfully',
            'ticket' => new TicketResource($ticket),
        ]);
    }

    public function updatePriority(Ticket $ticket, UpdatePriorityRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('manage', $ticket);

        $oldPriority = $ticket->priority->value;
        $newPriority = TicketPriority::from($request->priority);

        DB::transaction(function () use ($ticket, $newPriority, $oldPriority, $user, $request) {
            $ticket->update(['priority' => $newPriority]);
            $this->slaCalculator->attachDeadlines($ticket->fresh(['organization', 'department']));

            $this->auditLogger->log($ticket, 'priority_changed', 'priority', $oldPriority, $newPriority->value, $user, $request->ip());
        });

        $ticket->load(self::RELATIONS);

        return response()->json([
            'message' => 'Priority updated and open SLA deadlines recalculated',
            'ticket' => new TicketResource($ticket),
        ]);
    }

    public function pingPresence(Ticket $ticket, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('view', $ticket);

        $request->validate(['action' => ['nullable', 'in:viewing,typing']]);

        if ($user->role->isStaff()) {
            $this->presenceService->recordPresence($ticket->id, $user, $request->input('action', 'viewing'));
        }

        return response()->json([
            'status' => 'acknowledged',
            'active_collisions' => $user->role->isStaff()
                ? $this->presenceService->getActiveCollisions($ticket->id, $user->id)
                : [],
        ]);
    }
}
