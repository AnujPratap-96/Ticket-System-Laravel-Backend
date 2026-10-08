<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSlaPolicyRequest;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlaAnalyticsController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $scope = $this->departmentScope($request);

        // One query for all four SLA figures (this page used to cost 9 round trips to the database).
        $now = now();
        $row = TicketSlaDeadline::query()
            ->when($scope, fn ($q) => $q->whereHas('ticket', fn ($t) => $t->where('department_id', $scope)))
            ->selectRaw(
                'count(*) as total,
                 sum(case when is_breached = ? or (is_fulfilled = ? and paused_at is null and target_deadline < ?) then 1 else 0 end) as breached,
                 sum(case when is_fulfilled = ? and is_breached = ? then 1 else 0 end) as on_time,
                 sum(case when is_fulfilled = ? and paused_at is null and target_deadline >= ? and target_deadline <= ? then 1 else 0 end) as at_risk',
                [true, false, $now, true, false, false, $now, $now->copy()->addHour()]
            )->first();

        $total = (int) $row->total;
        $breached = (int) $row->breached;
        $fulfilledOnTime = (int) $row->on_time;
        $atRisk = (int) $row->at_risk;
        $compliance = $total > 0 ? round(($fulfilledOnTime / $total) * 100, 2) : 100.00;

        $byStatus = Ticket::query()->when($scope, fn ($q) => $q->where('department_id', $scope))
            ->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::IN_PROGRESS->value, TicketStatus::RESOLVED->value])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $tickets = fn (TicketStatus $st) => (int) ($byStatus[$st->value] ?? 0);

        $r = TicketRating::query()->when($scope, fn ($q) => $q->whereHas('ticket', fn ($t) => $t->where('department_id', $scope)))
            ->selectRaw('count(*) as n, avg(rating) as avg')->first();
        $csatCount = (int) $r->n;

        return response()->json([
            'csat_average' => $csatCount ? round((float) $r->avg, 2) : null,
            'csat_count' => $csatCount,
            'compliance_rate_percentage' => $compliance,
            'total_sla_tracked' => $total,
            'breached_count' => $breached,
            'fulfilled_on_time_count' => $fulfilledOnTime,
            'at_risk_deadlines_count' => $atRisk,
            'tickets_summary' => [
                'open' => $tickets(TicketStatus::OPEN),
                'in_progress' => $tickets(TicketStatus::IN_PROGRESS),
                'resolved' => $tickets(TicketStatus::RESOLVED),
            ],
        ]);
    }

    public function agentWorkload(Request $request): JsonResponse
    {
        $scope = $this->departmentScope($request);

        $csat = TicketRating::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_ratings.ticket_id')
            ->whereNotNull('tickets.assigned_agent_id')
            ->groupBy('tickets.assigned_agent_id')
            ->selectRaw('tickets.assigned_agent_id as agent_id, ROUND(AVG(ticket_ratings.rating), 2) as avg_rating')
            ->pluck('avg_rating', 'agent_id');

        $agents = User::whereIn('role', [UserRole::AGENT->value, UserRole::LEAD->value])
            ->when($scope, fn ($q) => $q->where('department_id', $scope))
            ->with('department')
            ->withCount(['assignedTickets as active_tickets_count' => function ($q) {
                $q->whereIn('status', [TicketStatus::OPEN->value, TicketStatus::IN_PROGRESS->value]);
            }])
            ->get()
            ->map(fn (User $agent) => [
                'agent_id' => $agent->id,
                'name' => $agent->name,
                'email' => $agent->email,
                'role' => $agent->role->value,
                'department' => $agent->department?->name ?? 'Unassigned',
                'active_tickets' => (int) $agent->active_tickets_count,
                'max_capacity' => (int) $agent->max_active_tickets,
                'utilization_percentage' => round(
                    ($agent->active_tickets_count / max(1, $agent->max_active_tickets)) * 100,
                    1
                ),
                'is_available' => $agent->is_available_for_routing,
                'csat_average' => $csat[$agent->id] ?? null,
            ]);

        return response()->json(['workload' => $agents]);
    }

    public function policies(): JsonResponse
    {
        return response()->json([
            'policies' => SlaPolicy::orderBy('tier')->orderBy('priority')->get(),
        ]);
    }

    public function storePolicy(StoreSlaPolicyRequest $request): JsonResponse
    {
        $policy = SlaPolicy::updateOrCreate(
            ['tier' => $request->tier, 'priority' => $request->priority],
            [
                'name' => $request->name,
                'first_response_time_minutes' => $request->first_response_time_minutes,
                'resolution_time_minutes' => $request->resolution_time_minutes,
                'applies_business_hours_only' => $request->boolean('applies_business_hours_only', true),
            ]
        );

        return response()->json([
            'message' => 'SLA Policy updated successfully',
            'policy' => $policy,
        ]);
    }

    /**
     * Leads see only their own department; admins see everything (optionally filtered).
     */
    private function departmentScope(Request $request): ?int
    {
        $user = $request->user();

        if ($user->role === UserRole::ADMIN) {
            return $request->filled('department_id') ? (int) $request->query('department_id') : null;
        }

        return $user->department_id ?? 0;
    }
}
