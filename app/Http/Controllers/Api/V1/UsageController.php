<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Organization;
use App\Models\WebhookEndpoint;
use App\Services\Ai\AiClient;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;

/** Admin: how much of each limit is in use, in one place. */
class UsageController extends Controller
{
    public function __invoke(PlanLimits $limits, AiClient $ai): JsonResponse
    {
        $global = config('plans.global');

        $orgs = Organization::where('domain', '!=', 'default.local')->orderBy('name')->get()->map(function (Organization $o) use ($limits) {
            $l = $limits->for($o);
            $u = $limits->usage($o);
            $worst = collect([
                $l['monthly_tickets'] ? $u['tickets_this_month'] / $l['monthly_tickets'] : 0,
                $l['customers'] ? $u['customers'] / $l['customers'] : 0,
            ])->max();

            return ['id' => $o->id, 'name' => $o->name, 'plan' => $o->sla_tier->value, 'limits' => $l, 'usage' => $u, 'percent' => (int) round($worst * 100)];
        })->sortByDesc('percent')->values();

        return response()->json([
            'month' => now()->format('F Y'),
            'organizations' => $orgs,
            'ai' => ['calls_today' => $ai->usageToday(), 'daily_limit' => (int) config('services.ai.daily_limit') ?: null, 'enabled' => $ai->enabled()],
            'platform' => [
                ['label' => 'Webhooks', 'used' => WebhookEndpoint::count(), 'limit' => $global['webhooks']],
                ['label' => 'Automation rules', 'used' => AutomationRule::count(), 'limit' => $global['automation_rules']],
            ],
            'rates' => config('plans.rate'),
        ]);
    }
}
