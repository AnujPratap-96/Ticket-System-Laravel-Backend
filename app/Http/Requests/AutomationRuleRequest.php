<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutomationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'trigger' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'idle_hours' => ['required_if:trigger,idle', 'nullable', 'integer', 'min:1', 'max:720'],
            'is_active' => ['sometimes', 'boolean'],
            'conditions' => ['present', 'array', 'max:8'],
            'conditions.*.field' => ['required', Rule::in(AutomationEngine::FIELDS)],
            'conditions.*.op' => ['required', Rule::in(AutomationEngine::OPS)],
            'conditions.*.value' => ['required', 'string', 'max:200'],
            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.type' => ['required', Rule::in(AutomationEngine::ACTIONS)],
            'actions.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            foreach ((array) $this->input('actions', []) as $i => $a) {
                $v = (string) ($a['value'] ?? '');
                $bad = match ($a['type'] ?? '') {
                    'set_priority' => TicketPriority::tryFrom($v) === null,
                    'set_status' => TicketStatus::tryFrom($v) === null,
                    'assign_to' => ! User::where('id', (int) $v)->whereIn('role', ['agent', 'lead'])->exists(),
                    'add_tag' => ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,29}$/', $v),
                    default => false,
                };
                if ($bad) {
                    $validator->errors()->add("actions.{$i}.value", 'This value is not valid for the chosen action.');
                }
            }
            foreach ((array) $this->input('conditions', []) as $i => $c) {
                $f = $c['field'] ?? '';
                $v = (string) ($c['value'] ?? '');
                $bad = match ($f) {
                    'priority' => TicketPriority::tryFrom($v) === null,
                    'status' => TicketStatus::tryFrom($v) === null,
                    'assigned' => ! in_array($v, ['yes', 'no'], true),
                    'channel' => ! in_array($v, ['portal', 'email', 'api'], true),
                    'organization_tier' => ! in_array($v, ['standard', 'silver', 'gold', 'platinum'], true),
                    'department_id' => ! ctype_digit($v),
                    default => false,
                };
                if ($bad || (in_array($f, ['priority', 'status', 'assigned', 'channel', 'organization_tier', 'department_id'], true) && ! in_array($c['op'] ?? '', ['is', 'is_not'], true))) {
                    $validator->errors()->add("conditions.{$i}.value", 'This condition is not valid.');
                }
            }
        }];
    }
}
