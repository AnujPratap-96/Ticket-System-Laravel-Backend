<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketTimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeEntryController extends Controller
{
    public function index(Ticket $ticket): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $entries = $ticket->timeEntries()->with('user:id,name')->latest('id')->get();

        return response()->json([
            'entries' => $entries->map(fn ($e) => $this->shape($e)),
            'total_minutes' => (int) $entries->sum('minutes'),
            'billable_minutes' => (int) $entries->where('billable', true)->sum('minutes'),
        ]);
    }

    public function store(Ticket $ticket, Request $request): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'note' => ['nullable', 'string', 'max:255'],
            'billable' => ['sometimes', 'boolean'],
        ]);
        $entry = $ticket->timeEntries()->create($data + ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Time logged', 'entry' => $this->shape($entry->load('user:id,name'))], 201);
    }

    /** Your own entries only; admins may remove anyone's. */
    public function destroy(Ticket $ticket, TicketTimeEntry $entry, Request $request): JsonResponse
    {
        $this->authorize('manage', $ticket);
        abort_unless($entry->ticket_id === $ticket->id, 404);
        abort_unless($entry->user_id === $request->user()->id || $request->user()->role === UserRole::ADMIN, 403, 'You can only remove your own time entries.');
        $entry->delete();

        return response()->json(['message' => 'Time entry removed']);
    }

    private function shape(TicketTimeEntry $e): array
    {
        return ['id' => $e->id, 'minutes' => $e->minutes, 'note' => $e->note, 'billable' => $e->billable, 'user' => $e->user?->only(['id', 'name']), 'created_at' => $e->created_at->toISOString()];
    }
}
