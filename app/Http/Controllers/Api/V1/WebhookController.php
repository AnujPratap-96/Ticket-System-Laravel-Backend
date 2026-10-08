<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhookJob;
use App\Models\Ticket;
use App\Models\WebhookEndpoint;
use App\Services\TicketEvents;
use App\Support\SafeUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin: send ticket events to Slack, Microsoft Teams or any HTTPS endpoint. */
class WebhookController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'webhooks' => WebhookEndpoint::orderBy('id')->get()->map(fn ($w) => $this->present($w)),
            'events' => WebhookEndpoint::EVENTS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $w = WebhookEndpoint::create($data + ['created_by' => $request->user()->id, 'secret' => $data['type'] === 'generic' ? Str::random(40) : null]);

        // The signing secret is shown exactly once, here.
        return response()->json(['message' => 'Webhook added', 'webhook' => $this->present($w), 'secret' => $w->secret], 201);
    }

    public function update(WebhookEndpoint $webhook, Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        if (empty($data['url'])) {
            unset($data['url']);             // blank = keep the stored address
        }
        $webhook->update($data + ($request->boolean('is_active') && ! $webhook->is_active ? ['consecutive_failures' => 0] : []));

        return response()->json(['message' => 'Webhook updated', 'webhook' => $this->present($webhook->fresh())]);
    }

    public function destroy(WebhookEndpoint $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(['message' => 'Webhook deleted']);
    }

    /** Sends a harmless sample so the admin can see it arrive. */
    public function test(WebhookEndpoint $webhook, TicketEvents $events): JsonResponse
    {
        $sample = Ticket::latest('id')->first() ?? new Ticket(['ticket_number' => 'TICK-TEST', 'title' => 'Test notification from DeskFlow', 'status' => 'open', 'priority' => 'medium']);
        $payload = $events->payload('ticket.created', $sample, ['test' => true]);
        $payload['ticket']['title'] = 'Test notification from DeskFlow';

        DeliverWebhookJob::dispatchSync($webhook->id, $payload, (string) Str::uuid());

        return response()->json(['message' => 'Test sent', 'webhook' => $this->present($webhook->fresh())]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(WebhookEndpoint::TYPES)],
            'url' => [$creating ? 'required' : 'nullable', 'string', 'max:1000', 'url'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(WebhookEndpoint::EVENTS))],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['url'])) {
            $check = SafeUrl::resolve($data['url']);
            if (is_string($check)) {
                abort(422, $check);
            }
        }

        $data['events'] = array_values(array_unique($data['events']));

        return $data;
    }

    private function present(WebhookEndpoint $w): array
    {
        return [
            'id' => $w->id, 'name' => $w->name, 'type' => $w->type, 'url_hint' => $w->maskedUrl(), 'events' => $w->events,
            'is_active' => $w->is_active, 'last_status' => $w->last_status, 'last_delivery_at' => $w->last_delivery_at?->toISOString(),
            'consecutive_failures' => $w->consecutive_failures,
        ];
    }
}
