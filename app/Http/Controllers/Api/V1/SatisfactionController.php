<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TicketRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer satisfaction over time, per agent, and the unhappy ratings that need a follow-up.
 * Leads see their department, admins everything.
 */
class SatisfactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:180']]);
        $days = (int) $request->query('days', 30);
        $since = now()->subDays($days - 1)->startOfDay();
        $user = $request->user();

        $ratings = TicketRating::query()
            ->with(['ticket:id,ticket_number,title,assigned_agent_id,department_id', 'ticket.assignedAgent:id,name'])
            ->where('created_at', '>=', $since)
            ->when($user->role !== UserRole::ADMIN, fn ($q) => $q->whereHas('ticket', fn ($t) => $t->where('department_id', $user->department_id ?? 0)))
            ->orderByDesc('created_at')->get();

        $avg = fn ($c) => $c->isEmpty() ? null : round($c->avg('rating'), 2);

        $series = [];
        for ($d = 0; $d < $days; $d++) {
            $date = $since->copy()->addDays($d)->format('Y-m-d');
            $series[$date] = ['date' => $date, 'count' => 0, 'average' => null];
        }
        foreach ($ratings->groupBy(fn ($r) => $r->created_at->format('Y-m-d')) as $date => $group) {
            $series[$date] = ['date' => $date, 'count' => $group->count(), 'average' => $avg($group)];
        }

        $byAgent = $ratings->filter(fn ($r) => $r->ticket?->assigned_agent_id)
            ->groupBy(fn ($r) => $r->ticket->assigned_agent_id)
            ->map(fn ($g) => ['agent_id' => $g->first()->ticket->assigned_agent_id, 'agent' => $g->first()->ticket->assignedAgent?->name ?? 'Unknown', 'count' => $g->count(), 'average' => $avg($g)])
            ->sortByDesc('average')->values();

        $low = $ratings->filter(fn ($r) => $r->rating <= 2)->take(20)->map(fn ($r) => [
            'ticket_id' => $r->ticket_id,
            'ticket_number' => $r->ticket?->ticket_number,
            'title' => $r->ticket?->title,
            'agent' => $r->ticket?->assignedAgent?->name,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'created_at' => $r->created_at->toISOString(),
        ])->values();

        return response()->json([
            'days' => $days,
            'average' => $avg($ratings),
            'count' => $ratings->count(),
            'distribution' => collect(range(1, 5))->mapWithKeys(fn ($n) => [$n => $ratings->where('rating', $n)->count()]),
            'series' => array_values($series),
            'by_agent' => $byAgent,
            'low_ratings' => $low,
        ]);
    }
}
