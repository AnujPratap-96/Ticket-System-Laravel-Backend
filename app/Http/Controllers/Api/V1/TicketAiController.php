<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Ai\TicketAiService;
use App\Services\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketAiController extends Controller
{
    public function draft(Ticket $ticket, Request $request, TicketAiService $ai, AiClient $client, AuditLoggerService $audit): JsonResponse
    {
        $this->authorize('manage', $ticket);
        abort_unless($client->enabled(), 503, 'The AI assistant is turned off.');

        $draft = $ai->draftReply($ticket, $request->user());
        abort_if($draft === null, 503, 'The AI service is busy right now. Please try again in a moment.');

        $audit->log($ticket, 'ai_draft_generated', null, null, null, $request->user(), $request->ip());

        return response()->json(['draft' => $draft]);
    }

    public function summary(Ticket $ticket, Request $request, TicketAiService $ai, AiClient $client): JsonResponse
    {
        $this->authorize('manage', $ticket);
        abort_unless($client->enabled(), 503, 'The AI assistant is turned off.');

        $summary = $ai->summarize($ticket);
        abort_if($summary === null, 503, 'The AI service is busy right now. Please try again in a moment.');

        return response()->json(['summary' => $summary]);
    }
}
