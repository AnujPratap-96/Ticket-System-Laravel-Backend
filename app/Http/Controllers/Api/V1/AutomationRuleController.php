<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationRuleRequest;
use App\Models\AutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AutomationRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['rules' => AutomationRule::orderBy('id')->get()]);
    }

    public function store(AutomationRuleRequest $request): JsonResponse
    {
        $rule = AutomationRule::create($this->payload($request) + ['created_by' => $request->user()->id, 'is_active' => $request->boolean('is_active', true)]);

        return response()->json(['message' => 'Rule created', 'rule' => $rule], 201);
    }

    public function update(AutomationRule $rule, AutomationRuleRequest $request): JsonResponse
    {
        $rule->update($this->payload($request) + ['is_active' => $request->boolean('is_active', $rule->is_active)]);

        return response()->json(['message' => 'Rule updated', 'rule' => $rule->fresh()]);
    }

    public function toggle(AutomationRule $rule): JsonResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json(['message' => $rule->is_active ? 'Rule turned on' : 'Rule turned off', 'rule' => $rule]);
    }

    public function destroy(AutomationRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['message' => 'Rule deleted']);
    }

    /** Recent tickets a rule acted on. */
    public function runs(AutomationRule $rule, Request $request): JsonResponse
    {
        $runs = DB::table('automation_runs')->join('tickets', 'tickets.id', '=', 'automation_runs.ticket_id')
            ->where('rule_id', $rule->id)->orderByDesc('automation_runs.id')->limit(20)
            ->get(['automation_runs.created_at', 'automation_runs.applied', 'tickets.id as ticket_id', 'tickets.ticket_number']);

        return response()->json(['runs' => $runs->map(fn ($r) => ['ticket_id' => $r->ticket_id, 'ticket_number' => $r->ticket_number, 'applied' => json_decode($r->applied, true), 'created_at' => $r->created_at])]);
    }

    private function payload(AutomationRuleRequest $r): array
    {
        $d = $r->validated();

        return [
            'name' => $d['name'],
            'trigger' => $d['trigger'],
            'idle_hours' => $d['trigger'] === 'idle' ? (int) $d['idle_hours'] : null,
            'conditions' => array_values(array_map(fn ($c) => ['field' => $c['field'], 'op' => $c['op'], 'value' => $c['value']], $d['conditions'])),
            'actions' => array_values(array_map(fn ($a) => ['type' => $a['type'], 'value' => $a['value']], $d['actions'])),
        ];
    }
}
