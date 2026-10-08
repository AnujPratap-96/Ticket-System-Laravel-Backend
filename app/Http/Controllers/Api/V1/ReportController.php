<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAudit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Daily created/resolved volume and average response/resolution times.
     * Aggregated in PHP (bounded window) so it is identical on MySQL and Postgres.
     */
    public function trends(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:180']]);
        $days = (int) $request->query('days', 30);
        $since = now()->subDays($days - 1)->startOfDay();

        $tickets = $this->scoped($request)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('resolved_at', '>=', $since))
            ->get(['id', 'created_at', 'resolved_at', 'first_responded_at', 'department_id']);

        $series = [];
        for ($d = 0; $d < $days; $d++) {
            $series[$since->copy()->addDays($d)->format('Y-m-d')] = ['date' => $since->copy()->addDays($d)->format('Y-m-d'), 'created' => 0, 'resolved' => 0];
        }
        foreach ($tickets as $t) {
            if ($t->created_at >= $since) {
                $series[$t->created_at->format('Y-m-d')]['created']++;
            }
            if ($t->resolved_at && $t->resolved_at >= $since) {
                $series[$t->resolved_at->format('Y-m-d')]['resolved']++;
            }
        }

        $minutes = fn ($rows, $from, $to) => $rows->filter(fn ($t) => $t->{$from} && $t->{$to})
            ->map(fn ($t) => $t->{$from}->diffInMinutes($t->{$to}))->avg();

        $window = $tickets->filter(fn ($t) => $t->created_at >= $since);

        return response()->json([
            'days' => $days,
            'series' => array_values($series),
            'avg_first_response_minutes' => ($v = $minutes($window, 'created_at', 'first_responded_at')) !== null ? round($v) : null,
            'avg_resolution_minutes' => ($v = $minutes($window, 'created_at', 'resolved_at')) !== null ? round($v) : null,
            'total_created' => $window->count(),
            'total_resolved' => $tickets->filter(fn ($t) => $t->resolved_at && $t->resolved_at >= $since)->count(),
        ]);
    }

    public function exportTickets(Request $request): StreamedResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:open,in_progress,pending_customer,resolved,closed'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = $this->scoped($request)->with(['customer:id,name,email', 'assignedAgent:id,name', 'department:id,name', 'tags:id,name', 'rating'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->query('priority')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay()))
            ->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Ticket', 'Title', 'Status', 'Priority', 'Department', 'Customer', 'Customer email', 'Agent', 'Tags', 'Created', 'First response', 'Resolved', 'Rating']);

            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $t) {
                    fputcsv($out, array_map([$this, 'safe'], [
                        $t->ticket_number, $t->title, $t->status->value, $t->priority->value, $t->department?->name,
                        $t->customer?->name, $t->customer?->email, $t->assignedAgent?->name, $t->tags->pluck('name')->implode(' '),
                        $t->created_at?->toISOString(), $t->first_responded_at?->toISOString(), $t->resolved_at?->toISOString(), $t->rating?->rating,
                    ]));
                }
            });
            fclose($out);
        }, 'tickets-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Global audit trail for admins, with filters.
     */
    public function audits(Request $request): JsonResponse
    {
        $request->validate([
            'event_type' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer'],
            'ticket' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = TicketAudit::query()
            ->with(['actor:id,name,role', 'ticket:id,ticket_number'])
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->query('event_type')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', (int) $request->query('actor_id')))
            ->when($request->filled('ticket'), fn ($q) => $q->whereHas('ticket', fn ($t) => $t->where('ticket_number', 'like', '%'.addcslashes($request->query('ticket'), '%_\\').'%')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay()))
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($a) => [
                'id' => $a->id,
                'ticket_id' => $a->ticket_id,
                'ticket_number' => $a->ticket?->ticket_number,
                'event_type' => $a->event_type,
                'field_name' => $a->field_name,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'actor' => $a->actor ? ['id' => $a->actor->id, 'name' => $a->actor->name, 'role' => $a->actor->role->value] : null,
                'ip_address' => $a->ip_address,
                'created_at' => $a->created_at?->toISOString(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function scoped(Request $request)
    {
        $user = $request->user();
        $q = Ticket::query();

        // Reports are lead/admin only: leads see their department, admins everything.
        return $user->role === UserRole::ADMIN ? $q : $q->where('department_id', $user->department_id ?? 0);
    }

    /**
     * Neutralise spreadsheet formula injection (=, +, -, @, tab, CR at the start of a cell).
     */
    private function safe($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
