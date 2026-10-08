<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Ticket;
use App\Services\Ai\AiClient;
use App\Services\Ai\TicketAnalyzer;
use App\Services\Ai\TicketInsights;
use App\Services\AuditLoggerService;
use App\Services\SlaCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TicketInsightsController extends Controller
{
    /** Staff: tickets that look like this one (candidates to merge). */
    public function similar(Ticket $ticket, Request $request, TicketInsights $insights): JsonResponse
    {
        $this->authorize('manage', $ticket);

        return response()->json(['similar' => $insights->similarTo($ticket, $request->user())->map(fn ($r) => [
            'id' => $r['ticket']->id, 'ticket_number' => $r['ticket']->ticket_number, 'title' => $r['ticket']->title,
            'status' => $r['ticket']->status->value, 'score' => $r['score'],
        ])]);
    }

    /** Anyone filing a ticket: "you already have an open ticket about this". */
    public function openMatches(Request $request, TicketInsights $insights): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        return response()->json(['tickets' => $insights->openMatchesFor($request->user(), $data['title'])->map(fn ($r) => [
            'id' => $r['ticket']->id, 'ticket_number' => $r['ticket']->ticket_number, 'title' => $r['ticket']->title, 'status' => $r['ticket']->status->value,
        ])]);
    }

    /** Re-run sentiment/urgency/triage by hand. */
    public function analyze(Ticket $ticket, TicketAnalyzer $analyzer): JsonResponse
    {
        $this->authorize('manage', $ticket);

        $r = $analyzer->analyze($ticket);
        $ticket->forceFill(['sentiment' => $r['sentiment'], 'is_ai_urgent' => $r['urgent'], 'ai_triage' => $r['triage'] ?? $ticket->ai_triage])->saveQuietly();

        return response()->json(['ai' => ['sentiment' => $ticket->sentiment, 'urgent' => $ticket->is_ai_urgent, 'triage' => ($ticket->ai_triage['status'] ?? null) === 'suggested' ? $ticket->ai_triage : null]]);
    }

    public function acceptTriage(Ticket $ticket, Request $request, AuditLoggerService $audit, SlaCalculatorService $sla): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $t = $ticket->ai_triage;
        abort_unless(($t['status'] ?? null) === 'suggested', 422, 'There is no pending suggestion.');

        DB::transaction(function () use ($ticket, $t, $request, $audit, $sla) {
            if (! empty($t['priority']) && $ticket->priority->value !== $t['priority']) {
                $old = $ticket->priority->value;
                $ticket->update(['priority' => TicketPriority::from($t['priority'])]);
                $sla->attachDeadlines($ticket->fresh(['organization', 'department']));
                $audit->log($ticket, 'priority_changed', 'priority', $old, $t['priority'], $request->user(), $request->ip());
            }
            if (! empty($t['tags'])) {
                $before = $ticket->tags()->pluck('name')->sort()->implode(', ');
                $ticket->tags()->syncWithoutDetaching(collect($t['tags'])->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id)->all());
                $after = $ticket->tags()->pluck('name')->sort()->implode(', ');
                if ($before !== $after) {
                    $audit->log($ticket, 'tags_changed', 'tags', $before ?: null, $after, $request->user(), $request->ip());
                }
            }
            $ticket->forceFill(['ai_triage' => array_merge($t, ['status' => 'accepted'])])->saveQuietly();
            $audit->log($ticket, 'ai_triage_accepted', null, null, null, $request->user(), $request->ip());
        });

        return response()->json(['message' => 'Suggestion applied.']);
    }

    public function dismissTriage(Ticket $ticket): JsonResponse
    {
        $this->authorize('manage', $ticket);
        abort_unless(($ticket->ai_triage['status'] ?? null) === 'suggested', 422, 'There is no pending suggestion.');
        $ticket->forceFill(['ai_triage' => array_merge($ticket->ai_triage, ['status' => 'dismissed'])])->saveQuietly();

        return response()->json(['message' => 'Suggestion dismissed.']);
    }

    public function translate(Ticket $ticket, Request $request, TicketInsights $insights, AiClient $client): JsonResponse
    {
        $this->authorize('manage', $ticket);
        $data = $request->validate(['text' => ['required', 'string', 'max:4000'], 'language' => ['required', Rule::in(TicketInsights::LANGUAGES)]]);
        abort_unless($client->enabled(), 503, 'The AI assistant is turned off.');

        $out = $insights->translate($data['text'], $data['language']);
        abort_if($out === null, 503, 'The AI service is busy right now. Please try again in a moment.');

        return response()->json(['translation' => $out]);
    }
}
