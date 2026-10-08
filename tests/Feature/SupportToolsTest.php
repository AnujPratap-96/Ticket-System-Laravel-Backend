<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SupportToolsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Department $other;
    private Organization $org;
    private User $admin;
    private User $lead;
    private User $agent;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $mk = fn ($n) => Department::create(['name' => $n, 'slug' => strtolower($n), 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->dept = $mk('Infra');
        $this->other = $mk('Billing');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
    }

    private function ticket(array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-S-'.uniqid(), 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium',
        ], $a));
    }

    // ---- satisfaction -----------------------------------------------------------------------

    public function test_low_rating_alerts_the_department_lead_and_admin_but_not_others(): void
    {
        Notification::fake();
        $t = $this->ticket(['status' => 'resolved', 'assigned_agent_id' => $this->agent->id]);
        $otherLead = User::factory()->create(['role' => 'lead', 'department_id' => $this->other->id]);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/rating", ['rating' => 5])->assertStatus(201);
        Notification::assertNothingSent();

        $t2 = $this->ticket(['status' => 'resolved', 'assigned_agent_id' => $this->agent->id]);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t2->id}/rating", ['rating' => 1, 'comment' => 'Slow'])->assertStatus(201);

        Notification::assertSentTo($this->lead, TicketActivityNotification::class, fn ($n) => $n->kind === 'low_rating');
        Notification::assertSentTo($this->admin, TicketActivityNotification::class);
        Notification::assertNotSentTo($otherLead, TicketActivityNotification::class);
        Notification::assertNotSentTo($this->agent, TicketActivityNotification::class);
    }

    public function test_satisfaction_report_is_scoped_and_role_gated(): void
    {
        $mine = $this->ticket(['status' => 'resolved', 'assigned_agent_id' => $this->agent->id]);
        $theirs = $this->ticket(['status' => 'resolved', 'department_id' => $this->other->id]);
        TicketRating::create(['ticket_id' => $mine->id, 'customer_id' => $this->customer->id, 'rating' => 2, 'comment' => 'meh']);
        TicketRating::create(['ticket_id' => $this->ticket(['status' => 'resolved', 'assigned_agent_id' => $this->agent->id])->id, 'customer_id' => $this->customer->id, 'rating' => 4]);
        TicketRating::create(['ticket_id' => $theirs->id, 'customer_id' => $this->customer->id, 'rating' => 5]);

        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/analytics/satisfaction')->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/analytics/satisfaction')->assertStatus(403);

        $lead = $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/analytics/satisfaction?days=7')->assertOk();
        $lead->assertJsonPath('count', 2)->assertJsonPath('average', 3)->assertJsonCount(7, 'series');
        $this->assertSame($mine->ticket_number, $lead->json('low_ratings.0.ticket_number'));
        $this->assertSame(1, $lead->json('distribution.2'));

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/analytics/satisfaction')->assertOk()->assertJsonPath('count', 3);
    }

    // ---- customer SLA -----------------------------------------------------------------------

    public function test_customer_sees_their_plan_targets(): void
    {
        SlaPolicy::create(['name' => 'Gold urgent', 'tier' => 'gold', 'priority' => 'urgent', 'first_response_time_minutes' => 15, 'resolution_time_minutes' => 120, 'applies_business_hours_only' => false]);
        SlaPolicy::create(['name' => 'Silver urgent', 'tier' => 'silver', 'priority' => 'urgent', 'first_response_time_minutes' => 99, 'resolution_time_minutes' => 999, 'applies_business_hours_only' => true]);
        $this->ticket();

        $res = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/me/sla')->assertOk();
        $res->assertJsonPath('plan', 'gold')->assertJsonPath('organization', 'Acme')->assertJsonCount(1, 'targets')->assertJsonPath('targets.0.first_response_minutes', 15);
        $res->assertJsonPath('last_90_days.tickets', 1)->assertJsonPath('last_90_days.within_targets', 100);

        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/me/sla')->assertStatus(403);
    }

    // ---- saved views ------------------------------------------------------------------------

    public function test_saved_views_are_private_validated_and_limited(): void
    {
        $this->actingAs($this->agent, 'sanctum');
        $id = $this->postJson('/api/v1/saved-views', ['name' => 'Urgent mine', 'filters' => ['priority' => 'urgent', 'assigned_to' => 'me', 'evil' => 'x']])
            ->assertStatus(201)->assertJsonPath('view.filters.priority', 'urgent')->assertJsonMissingPath('view.filters.evil')->json('view.id');

        $this->postJson('/api/v1/saved-views', ['name' => 'Urgent mine', 'filters' => ['priority' => 'low']])->assertStatus(422);   // unique per user
        $this->postJson('/api/v1/saved-views', ['name' => 'Empty', 'filters' => []])->assertStatus(422);
        $this->postJson('/api/v1/saved-views', ['name' => 'Bad', 'filters' => ['priority' => 'nuclear']])->assertStatus(422);

        $this->assertCount(1, $this->getJson('/api/v1/saved-views')->json('views'));
        $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/saved-views')->assertJsonCount(0, 'views');       // not shared
        $this->actingAs($this->lead, 'sanctum')->deleteJson("/api/v1/saved-views/{$id}")->assertStatus(404);
        $this->actingAs($this->agent, 'sanctum')->deleteJson("/api/v1/saved-views/{$id}")->assertOk();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/saved-views')->assertStatus(403);
    }

    // ---- bulk -------------------------------------------------------------------------------

    public function test_bulk_priority_and_status_report_per_ticket_failures(): void
    {
        $mine = $this->ticket(['assigned_agent_id' => $this->agent->id]);
        $notMine = $this->ticket(['assigned_agent_id' => $this->lead->id]);   // invisible to the agent

        $res = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$mine->id, $notMine->id, 9999], 'action' => 'priority', 'priority' => 'high'])->assertOk();
        $this->assertSame([$mine->id], $res->json('updated'));
        $this->assertCount(2, $res->json('failed'));
        $this->assertSame('high', $mine->fresh()->priority->value);
        $this->assertSame('medium', $notMine->fresh()->priority->value);

        $res = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$mine->id], 'action' => 'status', 'status' => 'resolved'])->assertOk();
        $this->assertCount(1, $res->json('failed'));                           // open -> resolved is not a legal transition
        $this->assertSame('open', $mine->fresh()->status->value);

        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$mine->id], 'action' => 'status', 'status' => 'in_progress'])->assertOk()->assertJsonCount(0, 'failed');
    }

    public function test_bulk_assign_rules(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $foreignAgent = User::factory()->create(['role' => 'agent', 'department_id' => $this->other->id]);

        $res = $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$a->id, $b->id], 'action' => 'assign', 'agent_id' => $this->agent->id])->assertOk();
        $this->assertCount(2, $res->json('updated'));
        $this->assertSame($this->agent->id, $a->fresh()->assigned_agent_id);

        $res = $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$a->id], 'action' => 'assign', 'agent_id' => $foreignAgent->id])->assertOk();
        $this->assertCount(1, $res->json('failed'));                           // other department
        $this->assertSame($this->agent->id, $a->fresh()->assigned_agent_id);

        // an agent may only claim for themselves
        $free = $this->ticket();
        $res = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$free->id], 'action' => 'assign', 'agent_id' => $this->lead->id])->assertOk();
        $this->assertCount(1, $res->json('failed'));
        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$free->id], 'action' => 'assign', 'agent_id' => $this->agent->id])->assertOk()->assertJsonCount(1, 'updated');
    }

    public function test_bulk_validation_and_access(): void
    {
        $t = $this->ticket();
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$t->id], 'action' => 'priority', 'priority' => 'urgent'])->assertStatus(403);
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [], 'action' => 'priority'])->assertStatus(422);
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => range(1, 51), 'action' => 'priority', 'priority' => 'low'])->assertStatus(422);
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/tickets/bulk', ['ids' => [$t->id], 'action' => 'delete'])->assertStatus(422);
    }
}
