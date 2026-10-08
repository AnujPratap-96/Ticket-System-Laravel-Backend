<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AutomationRule;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "When X happens and these conditions hold, do these things": the admin's no-code rules.
 *
 * Rules are applied in the order they were created. Actions made by a rule never trigger other rules
 * (no loops), and a failing action is logged and skipped so it can never break filing or replying to a ticket.
 */
class AutomationEngine
{
    public const FIELDS = ['priority', 'department_id', 'status', 'channel', 'organization_tier', 'assigned', 'title', 'description'];
    public const OPS = ['is', 'is_not', 'contains', 'not_contains'];
    public const ACTIONS = ['set_priority', 'add_tag', 'assign_to', 'set_status', 'add_note'];

    private bool $running = false;

    public function __construct(
        private TicketStateMachineService $stateMachine,
        private TicketRoutingService $routing,
        private AuditLoggerService $audit,
        private SlaCalculatorService $sla,
    ) {}

    /** Run every active rule for this trigger against the ticket. */
    public function fire(string $trigger, Ticket $ticket): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        try {
            $ticket->loadMissing('organization', 'tags');
            // (a plain foreach: Collection::each() would stop at the first rule that does not match)
            foreach (AutomationRule::where('trigger', $trigger)->where('is_active', true)->orderBy('id')->get() as $rule) {
                $this->apply($rule, $ticket->refresh()->load('organization', 'tags'));
            }
        } catch (\Throwable $e) {
            Log::warning('Automation failed', ['trigger' => $trigger, 'ticket' => $ticket->id, 'error' => $e->getMessage()]);
        } finally {
            $this->running = false;
        }
    }

    /** Idle rules: find tickets quiet for N hours and apply once each. Returns how many tickets were changed. */
    public function runIdle(): int
    {
        $count = 0;
        $this->running = true;
        try {
            foreach (AutomationRule::where('trigger', 'idle')->where('is_active', true)->orderBy('id')->get() as $rule) {
                $cutoff = now()->subHours(max(1, (int) $rule->idle_hours));
                $tickets = Ticket::with('organization', 'tags')
                    ->whereNotIn('status', [TicketStatus::CLOSED->value])
                    ->where('updated_at', '<=', $cutoff)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('automation_runs')->whereColumn('automation_runs.ticket_id', 'tickets.id')->where('automation_runs.rule_id', $rule->id))
                    ->limit(100)->get();

                foreach ($tickets as $t) {
                    $count += $this->apply($rule, $t) ? 1 : 0;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Idle automation failed', ['error' => $e->getMessage()]);
        } finally {
            $this->running = false;
        }

        return $count;
    }

    public function matches(AutomationRule $rule, Ticket $t): bool
    {
        foreach ($rule->conditions as $c) {
            $actual = match ($c['field']) {
                'priority' => $t->priority->value,
                'department_id' => (string) $t->department_id,
                'status' => $t->status->value,
                'channel' => $t->channel->value,
                'organization_tier' => $t->organization?->sla_tier->value ?? 'standard',
                'assigned' => $t->assigned_agent_id ? 'yes' : 'no',
                'title' => Str::lower($t->title),
                'description' => Str::lower($t->description),
                default => null,
            };
            $want = Str::lower((string) $c['value']);
            $ok = match ($c['op']) {
                'is' => (string) $actual === $want,
                'is_not' => (string) $actual !== $want,
                'contains' => $actual !== null && str_contains((string) $actual, $want),
                'not_contains' => $actual === null || ! str_contains((string) $actual, $want),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function apply(AutomationRule $rule, Ticket $ticket): bool
    {
        if (! $this->matches($rule, $ticket)) {
            return false;
        }

        $actor = ($rule->created_by ? User::find($rule->created_by) : null) ?? User::where('role', UserRole::ADMIN->value)->where('is_active', true)->first();
        $applied = [];

        foreach ($rule->actions as $a) {
            try {
                if ($done = $this->perform($a, $ticket, $actor, $rule)) {
                    $applied[] = $done;
                    $ticket->refresh();
                }
            } catch (\Throwable $e) {
                Log::warning('Automation action failed', ['rule' => $rule->id, 'action' => $a['type'], 'error' => $e->getMessage()]);
            }
        }

        DB::table('automation_runs')->insert(['rule_id' => $rule->id, 'ticket_id' => $ticket->id, 'applied' => json_encode($applied), 'created_at' => now()]);
        $rule->increment('runs_count');

        return true;
    }

    private function perform(array $a, Ticket $ticket, ?User $actor, AutomationRule $rule): ?string
    {
        $value = (string) ($a['value'] ?? '');
        $why = "Rule: {$rule->name}";

        switch ($a['type']) {
            case 'set_priority':
                $new = TicketPriority::from($value);
                if ($ticket->priority === $new) {
                    return null;
                }
                $old = $ticket->priority->value;
                $ticket->update(['priority' => $new]);
                $this->sla->attachDeadlines($ticket->fresh(['organization', 'department']));
                $this->audit->log($ticket, 'priority_changed', 'priority', $old, $new->value, $actor);
                $this->audit->log($ticket, 'automation_applied', null, null, $why, $actor);

                return "priority → {$new->value}";

            case 'add_tag':
                $name = Str::lower(trim(preg_replace('/\s+/', '-', $value)));
                if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,29}$/', $name) || $ticket->tags()->where('name', $name)->exists()) {
                    return null;
                }
                $ticket->tags()->attach(Tag::firstOrCreate(['name' => $name])->id);
                $this->audit->log($ticket, 'tags_changed', 'tags', null, $name, $actor);

                return "tag {$name}";

            case 'assign_to':
                $agent = User::where('id', (int) $value)->where('is_active', true)->whereIn('role', ['agent', 'lead'])->first();
                if (! $agent || $agent->department_id !== $ticket->department_id || $ticket->assigned_agent_id === $agent->id || ! $actor) {
                    return null;
                }
                $this->routing->assign($ticket, $agent, $actor, null);

                return "assigned to {$agent->name}";

            case 'set_status':
                $to = TicketStatus::from($value);
                if ($ticket->status === $to || ! $actor) {
                    return null;
                }
                $this->stateMachine->transition($ticket, $to, $actor, null);

                return "status → {$to->value}";

            case 'add_note':
                if ($value === '' || ! $actor) {
                    return null;
                }
                TicketMessage::create(['ticket_id' => $ticket->id, 'sender_id' => $actor->id, 'is_internal_note' => true, 'body' => Str::limit("[Automation: {$rule->name}] {$value}", 2000, '')]);

                return 'internal note';
        }

        return null;
    }
}
