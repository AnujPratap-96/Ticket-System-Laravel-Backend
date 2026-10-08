<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\Ticket;
use App\Services\AuditLoggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['tags' => Tag::orderBy('name')->pluck('name')]);
    }

    public function sync(Ticket $ticket, Request $request, AuditLoggerService $audit): JsonResponse
    {
        $this->authorize('manage', $ticket);

        $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['string', 'min:1', 'max:30'],
        ]);

        $names = collect($request->input('tags'))
            ->map(fn ($t) => Str::lower(trim(preg_replace('/\s+/', '-', $t))))
            ->filter(fn ($t) => $t !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $t))
            ->unique()
            ->values();

        $old = $ticket->tags()->pluck('name')->sort()->implode(', ');
        $ids = $names->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id);
        $ticket->tags()->sync($ids);
        $new = $ticket->tags()->pluck('name')->sort()->implode(', ');

        if ($old !== $new) {
            $audit->log($ticket, 'tags_changed', 'tags', $old ?: null, $new ?: null, $request->user(), $request->ip());
        }

        return response()->json(['tags' => $ticket->tags()->pluck('name')->values()]);
    }
}
