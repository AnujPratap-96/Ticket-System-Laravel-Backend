<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a customer's company is promised (its plan's targets) and how support has performed against them.
 */
class CustomerSlaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role->value === 'customer', 403);

        $org = $user->organization;
        $tier = $org?->sla_tier->value ?? 'standard';

        $targets = SlaPolicy::where('tier', $tier)->get()->sortBy(fn ($p) => array_search($p->priority->value, array_column(TicketPriority::cases(), 'value')))
            ->map(fn ($p) => [
                'priority' => $p->priority->value,
                'first_response_minutes' => $p->first_response_time_minutes,
                'resolution_minutes' => $p->resolution_time_minutes,
                'business_hours_only' => $p->applies_business_hours_only,
            ])->values();

        $mine = Ticket::visibleTo($user)->where('created_at', '>=', now()->subDays(90));
        $total = (clone $mine)->count();
        $answered = (clone $mine)->whereNotNull('first_responded_at')->count();
        $resolved = (clone $mine)->whereNotNull('resolved_at')->count();

        $breached = (clone $mine)->whereHas('slaDeadlines', fn ($q) => $q->where('is_breached', true))->count();

        return response()->json([
            'plan' => $tier,
            'organization' => $org?->name,
            'targets' => $targets,
            'last_90_days' => [
                'tickets' => $total,
                'answered' => $answered,
                'resolved' => $resolved,
                'within_targets' => $total === 0 ? null : (int) round(($total - $breached) / $total * 100),
            ],
        ]);
    }
}
