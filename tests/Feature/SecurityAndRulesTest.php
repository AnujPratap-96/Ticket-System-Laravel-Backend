<?php

namespace Tests\Feature;

use App\Enums\SlaMetricType;
use App\Models\BusinessHoliday;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAudit;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use App\Services\SlaCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Notifications\OtpNotification;
use App\Notifications\TicketReplyNotification;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class SecurityAndRulesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private Department $otherDept;
    private User $customer;
    private User $otherCustomer;
    private User $agent;
    private User $agent2;
    private User $otherDeptAgent;
    private User $lead;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->otherDept = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);

        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->otherCustomer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'max_active_tickets' => 1]);
        $this->agent2 = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'max_active_tickets' => 5]);
        $this->otherDeptAgent = User::factory()->create(['role' => 'agent', 'department_id' => $this->otherDept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function ticket(array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-T-'.uniqid(),
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id,
            'title' => 'T',
            'description' => 'D',
            'status' => 'open',
            'priority' => 'medium',
        ], $attrs));
    }

    public function test_register_ignores_role_and_org_from_client(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Evil', 'email' => 'evil@acme.test', 'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'admin', 'organization_id' => 999,
        ])->assertStatus(202);

        $code = null;
        Notification::assertSentOnDemand(OtpNotification::class, function ($n) use (&$code) { $code = $n->code; return true; });

        $res = $this->postJson('/api/v1/auth/verify-otp', ['email' => 'evil@acme.test', 'code' => $code])->assertStatus(201);

        $this->assertSame('customer', $res->json('user.role'));
        $this->assertSame($this->org->id, User::where('email', 'evil@acme.test')->first()->organization_id);
    }

    public function test_only_admin_can_create_staff_and_edit_sla_policies(): void
    {
        $payload = ['name' => 'N', 'email' => 'new@x.test', 'password' => 'password123', 'role' => 'agent', 'department_id' => $this->dept->id];
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/users', $payload)->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', $payload)->assertStatus(201);

        $policy = ['name' => 'P', 'tier' => 'gold', 'priority' => 'high', 'first_response_time_minutes' => 30, 'resolution_time_minutes' => 120];
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/sla-policies', $policy)->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/sla-policies', $policy)->assertStatus(200);
    }

    public function test_agent_cannot_access_other_department_ticket(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->otherDeptAgent, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertStatus(403);
        $this->actingAs($this->otherDeptAgent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'x'])->assertStatus(403);
        $this->actingAs($this->otherDeptAgent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(403);
        $this->actingAs($this->otherCustomer, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->assertStatus(403);
    }

    public function test_customer_status_rights_limited_to_close_and_reopen(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress']);

        $this->actingAs($this->customer, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'resolved'])->assertStatus(422);
        $this->actingAs($this->customer, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'closed'])->assertStatus(200);
        // closed is locked for non-admins
        $this->actingAs($this->customer, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(200);
    }

    public function test_audit_history_hidden_from_customers_and_agents(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(200);

        $this->assertNull($this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}")->json('ticket.audits'));
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}/audits")->assertStatus(403);
        $this->actingAs($this->agent, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}/audits")->assertStatus(403);
        $this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$ticket->id}/audits")->assertStatus(200)->assertJsonCount(1, 'audits');
    }

    public function test_audit_rows_are_immutable(): void
    {
        $ticket = $this->ticket();
        $audit = TicketAudit::create(['ticket_id' => $ticket->id, 'event_type' => 'x', 'created_at' => now()]);

        $this->expectException(LogicException::class);
        $audit->update(['event_type' => 'y']);
    }

    public function test_assignment_rules_and_claim_conflict(): void
    {
        $ticket = $this->ticket(['assigned_agent_id' => $this->agent2->id, 'status' => 'in_progress']);

        // A ticket owned by a colleague is not even visible to another agent, so it can be neither assigned nor stolen
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent2->id])->assertStatus(403);
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent->id])->assertStatus(403);
        // Lead cannot assign across departments
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->otherDeptAgent->id])->assertStatus(422);
        // Lead can reassign within department
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent->id])->assertStatus(200);
        // Target at capacity (agent has max 1) -> 409
        $second = $this->ticket(['status' => 'open']);
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/tickets/{$second->id}/assign", ['agent_id' => $this->agent->id])
            ->assertStatus(409)->assertJsonPath('code', 'agent_at_capacity');
    }

    public function test_agents_can_claim_the_unassigned_pool_and_a_lost_race_returns_409(): void
    {
        $pool = $this->ticket(['status' => 'open']);                                            // unassigned, same department
        $elsewhere = $this->ticket(['status' => 'open', 'department_id' => $this->otherDept->id]);

        // visible and claimable: the unassigned pool of my own department only
        $this->actingAs($this->agent2, 'sanctum')->getJson("/api/v1/tickets/{$pool->id}")->assertOk();
        $this->actingAs($this->agent2, 'sanctum')->getJson("/api/v1/tickets/{$elsewhere->id}")->assertStatus(403);

        // the other agent got there first: the stale request loses the race inside the lock
        $stale = Ticket::find($pool->id);
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$pool->id}/assign", ['agent_id' => $this->agent->id])->assertOk();

        try {
            app(\App\Services\TicketRoutingService::class)->assign($stale, $this->agent2, $this->agent2, null, $this->agent2->id);
            $this->fail('expected a 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($this->agent->id, $pool->fresh()->assigned_agent_id);

        // and once owned, the ticket disappears from other agents' view
        $this->actingAs($this->agent2, 'sanctum')->getJson("/api/v1/tickets/{$pool->id}")->assertStatus(403);
        $ids = collect($this->actingAs($this->agent2, 'sanctum')->getJson('/api/v1/tickets')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($pool->id));
        $this->assertFalse($ids->contains($elsewhere->id));
    }

    public function test_routing_prefers_lowest_load_and_round_robins_ties(): void
    {
        $this->agent->update(['max_active_tickets' => 5]);
        $this->agent2->update(['max_active_tickets' => 5, 'last_assigned_at' => now()->subHour()]);
        $this->agent->update(['last_assigned_at' => now()->subMinutes(5)]);
        $this->lead->update(['is_available_for_routing' => false]);

        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', [
            'department_id' => $this->dept->id, 'title' => 'a', 'description' => 'b',
        ])->assertStatus(201);

        // Equal load -> least recently assigned wins
        $this->assertSame($this->agent2->id, $res->json('ticket.assigned_agent.id'));
        $this->assertMatchesRegularExpression('/^TICK-\d{4}-\d{5}$/', $res->json('ticket.ticket_number'));
    }

    public function test_customer_cannot_spoof_organization(): void
    {
        $other = Organization::create(['name' => 'Other', 'domain' => 'other.test', 'sla_tier' => 'platinum', 'is_active' => true]);

        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', [
            'department_id' => $this->dept->id, 'title' => 'a', 'description' => 'b', 'organization_id' => $other->id,
        ])->assertStatus(201);

        $this->assertSame($this->org->id, $res->json('ticket.organization.id'));
    }

    public function test_priority_change_keeps_fulfilled_sla(): void
    {
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', [
            'department_id' => $this->dept->id, 'title' => 'a', 'description' => 'b',
        ])->assertStatus(201);
        $id = $res->json('ticket.id');
        $assignee = User::findOrFail(Ticket::findOrFail($id)->assigned_agent_id);                 // auto-routing picked someone

        $this->actingAs($assignee, 'sanctum')->postJson("/api/v1/tickets/{$id}/messages", ['body' => 'hello'])->assertStatus(201);
        $this->assertTrue((bool) TicketSlaDeadline::where('ticket_id', $id)->where('metric_type', 'first_response')->value('is_fulfilled'));

        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/tickets/{$id}/priority", ['priority' => 'urgent'])->assertStatus(200);

        $this->assertTrue((bool) TicketSlaDeadline::where('ticket_id', $id)->where('metric_type', 'first_response')->value('is_fulfilled'));
    }

    public function test_pending_customer_pauses_and_resumes_resolution_clock(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', [
            'department_id' => $this->dept->id, 'title' => 'a', 'description' => 'b', 'priority' => 'low',
        ])->assertStatus(201);
        $ticket = Ticket::find($res->json('ticket.id'));
        $assignee = User::findOrFail($ticket->assigned_agent_id);

        $this->actingAs($assignee, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertStatus(200);
        $before = TicketSlaDeadline::where('ticket_id', $ticket->id)->where('metric_type', 'resolution')->first()->target_deadline;

        $this->actingAs($assignee, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/status", ['status' => 'pending_customer'])->assertStatus(200);
        $this->assertNotNull(TicketSlaDeadline::where('ticket_id', $ticket->id)->where('metric_type', 'resolution')->first()->paused_at);

        Carbon::setTestNow('2026-10-07 12:00:00'); // two business hours pass while paused
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'here'])->assertStatus(201);

        $deadline = TicketSlaDeadline::where('ticket_id', $ticket->id)->where('metric_type', 'resolution')->first();
        $this->assertNull($deadline->paused_at);
        $this->assertEquals($before->copy()->addHours(2)->toDateTimeString(), $deadline->target_deadline->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_calculator_skips_holidays_and_returns_utc(): void
    {
        BusinessHoliday::create(['holiday_date' => '2026-10-12', 'name' => 'Holiday']);
        $calc = new SlaCalculatorService();

        // Friday 17:00, 120 min: 60 Fri + Monday holiday skipped -> Tuesday 10:00
        $deadline = $calc->calculateTargetTimestamp(Carbon::parse('2026-10-09 17:00:00', 'UTC'), 120, $this->dept, true);
        $this->assertSame('2026-10-13 10:00:00', $deadline->format('Y-m-d H:i:s'));

        // Department in a non-UTC zone still yields UTC
        $ist = new Department(['business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'Asia/Kolkata']);
        $d = $calc->calculateTargetTimestamp(Carbon::parse('2026-10-07 04:00:00', 'UTC'), 60, $ist, true); // 09:30 IST start
        $this->assertSame('UTC', $d->timezoneName);
        $this->assertSame('2026-10-07 05:00:00', $d->format('Y-m-d H:i:s')); // 09:30 IST + 60m = 10:30 IST = 05:00 UTC
    }

    public function test_breach_command_marks_overdue_deadlines(): void
    {
        $ticket = $this->ticket();
        TicketSlaDeadline::create(['ticket_id' => $ticket->id, 'metric_type' => SlaMetricType::RESOLUTION, 'target_deadline' => now()->subMinute()]);

        $this->artisan('sla:check-breaches')->assertSuccessful();

        $this->assertTrue((bool) TicketSlaDeadline::first()->is_breached);
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $ticket->id, 'event_type' => 'sla_breached']);
    }

    public function test_collision_only_when_other_agent_is_typing_and_returns_409(): void
    {
        $ticket = $this->ticket(['status' => 'in_progress']);

        $this->actingAs($this->agent2, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/presence", ['action' => 'viewing'])->assertStatus(200);
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'ok'])->assertStatus(201);

        $this->actingAs($this->agent2, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/presence", ['action' => 'typing'])->assertStatus(200);
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'again'])
            ->assertStatus(409)->assertJsonPath('code', 'agent_collision');
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'again', 'force_send' => true])->assertStatus(201);
    }

    public function test_departments_require_authentication(): void
    {
        $this->getJson('/api/v1/departments')->assertStatus(401);
    }

    public function test_per_page_is_capped(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/tickets?per_page=100000')->assertStatus(422);
    }

    public function test_lead_analytics_are_department_scoped(): void
    {
        $this->ticket();
        $this->ticket(['department_id' => $this->otherDept->id, 'status' => 'in_progress']);

        $res = $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/analytics/sla-overview')->assertStatus(200);
        $this->assertSame(1, $res->json('tickets_summary.open'));
        $this->assertSame(0, $res->json('tickets_summary.in_progress'));
    }

    public function test_customer_is_emailed_on_public_staff_reply_only(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['status' => 'in_progress']);

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'internal', 'is_internal_note' => true])->assertStatus(201);
        Notification::assertNothingSent();

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'public answer'])->assertStatus(201);
        Notification::assertSentTo($this->customer, TicketReplyNotification::class);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'thanks'])->assertStatus(201);
        Notification::assertSentToTimes($this->customer, TicketReplyNotification::class, 1);
    }
}
