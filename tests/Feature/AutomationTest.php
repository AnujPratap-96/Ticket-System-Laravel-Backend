<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private User $admin;
    private User $agent;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'is_available_for_routing' => false]);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
    }

    private function rule(array $o = []): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'name' => 'R', 'trigger' => 'ticket_created', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'value' => 'auto']], 'is_active' => true, 'created_by' => $this->admin->id,
        ], $o));
    }

    private function file(string $title = 'Server down', string $body = 'help', string $priority = 'medium')
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => $title, 'description' => $body, 'priority' => $priority]);
    }

    public function test_a_matching_rule_changes_the_new_ticket(): void
    {
        $this->rule(['conditions' => [['field' => 'title', 'op' => 'contains', 'value' => 'DOWN']], 'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'], ['type' => 'add_tag', 'value' => 'Outage'], ['type' => 'assign_to', 'value' => (string) $this->agent->id], ['type' => 'add_note', 'value' => 'Check the load balancer'],
        ]]);

        $res = $this->file()->assertStatus(201);
        $t = Ticket::find($res->json('ticket.id'));

        $this->assertSame('urgent', $t->priority->value);
        $this->assertSame(['outage'], $t->tags()->pluck('name')->all());
        $this->assertSame($this->agent->id, $t->assigned_agent_id);
        $this->assertStringContainsString('Check the load balancer', $t->messages()->where('is_internal_note', true)->first()->body);
        $this->assertSame(1, AutomationRule::first()->runs_count);
        $this->assertSame(4, count(json_decode(\DB::table('automation_runs')->value('applied'), true)));
    }

    public function test_non_matching_inactive_and_failing_rules_do_not_get_in_the_way(): void
    {
        $this->rule(['conditions' => [['field' => 'priority', 'op' => 'is', 'value' => 'urgent']]]);                 // does not match
        $this->rule(['is_active' => false]);                                                                         // off
        $this->rule(['actions' => [['type' => 'assign_to', 'value' => '99999'], ['type' => 'add_tag', 'value' => 'survivor']]]);   // first action impossible

        $t = Ticket::find($this->file()->assertStatus(201)->json('ticket.id'));

        $this->assertSame(['survivor'], $t->tags()->pluck('name')->all());
        $this->assertNull($t->assigned_agent_id);
    }

    public function test_conditions_are_all_required_and_support_not_operators(): void
    {
        $this->rule(['conditions' => [['field' => 'organization_tier', 'op' => 'is', 'value' => 'gold'], ['field' => 'description', 'op' => 'not_contains', 'value' => 'spam']]]);

        $this->assertSame(['auto'], Ticket::find($this->file('A', 'real problem')->json('ticket.id'))->tags()->pluck('name')->all());
        $this->assertSame([], Ticket::find($this->file('B', 'this is SPAM')->json('ticket.id'))->tags()->pluck('name')->all());
    }

    public function test_customer_reply_trigger_and_no_rule_loops(): void
    {
        $this->rule(['trigger' => 'customer_replied', 'name' => 'Escalate', 'actions' => [['type' => 'set_priority', 'value' => 'high']]]);
        $this->rule(['trigger' => 'customer_replied', 'name' => 'Second', 'actions' => [['type' => 'add_tag', 'value' => 'replied']]]);

        $t = Ticket::find($this->file()->json('ticket.id'));
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", ['body' => 'Any update?'])->assertStatus(201);

        $t->refresh();
        $this->assertSame('high', $t->priority->value);
        $this->assertSame(['replied'], $t->tags()->pluck('name')->all());
        $this->assertSame(2, \DB::table('automation_runs')->count());                       // each rule once
    }

    public function test_idle_rules_apply_once_to_quiet_tickets_only(): void
    {
        $this->rule(['trigger' => 'idle', 'idle_hours' => 24, 'name' => 'Nudge', 'actions' => [['type' => 'add_tag', 'value' => 'stale']]]);

        $quiet = Ticket::find($this->file('quiet')->json('ticket.id'));
        $fresh = Ticket::find($this->file('fresh')->json('ticket.id'));
        $closed = Ticket::find($this->file('closed')->json('ticket.id'));
        Ticket::whereKey([$quiet->id, $closed->id])->update(['updated_at' => now()->subHours(30)]);
        $closed->forceFill(['status' => 'closed'])->saveQuietly();
        Ticket::whereKey($closed->id)->update(['updated_at' => now()->subHours(30)]);

        $this->assertSame(1, app(AutomationEngine::class)->runIdle());
        $this->assertSame(0, app(AutomationEngine::class)->runIdle());                      // not again for the same ticket
        $this->assertSame(['stale'], $quiet->tags()->pluck('name')->all());
        $this->assertSame([], $fresh->tags()->pluck('name')->all());
        $this->assertSame([], $closed->tags()->pluck('name')->all());
    }

    public function test_admin_manages_rules_with_validation(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $ok = ['name' => 'Urgent VIP', 'trigger' => 'ticket_created', 'conditions' => [['field' => 'organization_tier', 'op' => 'is', 'value' => 'gold']], 'actions' => [['type' => 'set_priority', 'value' => 'high']]];

        $id = $this->postJson('/api/v1/automation-rules', $ok)->assertStatus(201)->json('rule.id');
        $this->postJson('/api/v1/automation-rules', ['trigger' => 'idle'] + $ok)->assertStatus(422);                                                        // idle needs hours
        $this->postJson('/api/v1/automation-rules', ['actions' => [['type' => 'set_priority', 'value' => 'nuclear']]] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/automation-rules', ['actions' => [['type' => 'drop_database', 'value' => 'x']]] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/automation-rules', ['conditions' => [['field' => 'priority', 'op' => 'contains', 'value' => 'high']]] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/automation-rules', ['actions' => []] + $ok)->assertStatus(422);

        $this->postJson("/api/v1/automation-rules/{$id}/toggle")->assertOk()->assertJsonPath('rule.is_active', false);
        $this->patchJson("/api/v1/automation-rules/{$id}", ['name' => 'Renamed'] + $ok)->assertOk()->assertJsonPath('rule.name', 'Renamed');
        $this->getJson('/api/v1/automation-rules')->assertJsonCount(1, 'rules');
        $this->deleteJson("/api/v1/automation-rules/{$id}")->assertOk();
    }

    public function test_only_admins_can_touch_rules(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->actingAs($lead, 'sanctum')->getJson('/api/v1/automation-rules')->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/automation-rules', [])->assertStatus(403);
    }
}
