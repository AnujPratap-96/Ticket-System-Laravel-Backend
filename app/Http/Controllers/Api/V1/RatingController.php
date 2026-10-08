<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Services\AuditLoggerService;
use App\Services\TicketNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    /**
     * The ticket's customer rates the support once the ticket is resolved or closed.
     */
    public function store(Ticket $ticket, Request $request, AuditLoggerService $audit, TicketNotifier $notifier): JsonResponse
    {
        $user = $request->user();
        abort_unless($ticket->customer_id === $user->id, 403, 'Only the ticket owner can rate it.');
        abort_unless(in_array($ticket->status, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true), 422, 'You can rate a ticket once it is resolved.');
        abort_if($ticket->rating()->exists(), 409, 'You have already rated this ticket.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $rating = TicketRating::create($data + ['ticket_id' => $ticket->id, 'customer_id' => $user->id]);
        $audit->log($ticket, 'rated', 'rating', null, (string) $rating->rating, $user, $request->ip());

        if ($rating->rating <= 2) {
            $notifier->lowRating($ticket, $rating->rating, $rating->comment);
        }

        return response()->json(['rating' => ['rating' => $rating->rating, 'comment' => $rating->comment]], 201);
    }
}
