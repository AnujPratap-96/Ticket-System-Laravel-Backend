<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CannedTagsRatingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private Department $other;
    private User $customer;
    private User $agent;
    private User $agent2;
    private User $lead;
    private User $admin;
    private User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->other = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->agent2 = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->otherAgent = User::factory()->create(['role' => 'agent', 'department_id' => $this->other->id]);
    }

    private function ticket(array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-C-'.uniqid(), 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium',
        ], $a));
    }

    public function test_canned_response_visibility_and_permissions(): void
    {
        $mine = $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/canned-responses', ['title' => 'Mine', 'body' => 'Hi {{customer_name}}'])->assertStatus(201)->json('canned_response.id');
        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/canned-responses', ['title' => 'X', 'body' => 'y', 'scope' => 'global'])->assertStatus(403);
        $this->actingAs($this->agent, 'sanctum')->postJson('/api/v1/canned-responses', ['title' => 'X', 'body' => 'y', 'scope' => 'department'])->assertStatus(403);

        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/canned-responses', ['title' => 'Dept', 'body' => 'b', 'scope' => 'department'])->assertStatus(201);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/canned-responses', ['title' => 'Global', 'body' => 'b', 'scope' => 'global'])->assertStatus(201);

        $titles = fn ($u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/canned-responses')->assertOk()->json('canned_responses'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Mine', 'Dept', 'Global'], $titles($this->agent));
        $this->assertEqualsCanonicalizing(['Dept', 'Global'], $titles($this->agent2));       // no one else's personal
        $this->assertEqualsCanonicalizing(['Global'], $titles($this->otherAgent));            // other department excluded

        $this->actingAs($this->agent2, 'sanctum')->patchJson("/api/v1/canned-responses/{$mine}", ['title' => 'Hack', 'body' => 'b'])->assertStatus(403);
        $this->actingAs($this->agent, 'sanctum')->deleteJson("/api/v1/canned-responses/{$mine}")->assertOk();
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/canned-responses')->assertStatus(403);
    }

    public function test_tags_are_staff_only_normalised_and_audited(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->customer, 'sanctum')->putJson("/api/v1/tickets/{$t->id}/tags", ['tags' => ['x']])->assertStatus(403);
        $this->actingAs($this->otherAgent, 'sanctum')->putJson("/api/v1/tickets/{$t->id}/tags", ['tags' => ['x']])->assertStatus(403);

        $res = $this->actingAs($this->agent, 'sanctum')->putJson("/api/v1/tickets/{$t->id}/tags", ['tags' => ['VIP Client', 'vip-client', 'bug', '!!']])->assertOk();
        $this->assertEqualsCanonicalizing(['vip-client', 'bug'], $res->json('tags'));
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $t->id, 'event_type' => 'tags_changed']);

        // Visible to staff, hidden from the customer
        $this->assertContains('bug', $this->actingAs($this->agent, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket.tags'));
        $this->assertArrayNotHasKey('tags', $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket'));

        // Filter list by tag
        $other = $this->ticket();
        $ids = collect($this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/tickets?tag=bug')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($t->id));
        $this->assertFalse($ids->contains($other->id));

        $this->actingAs($this->agent, 'sanctum')->putJson("/api/v1/tickets/{$t->id}/tags", ['tags' => range(1, 11)])->assertStatus(422);
    }

    public function test_rating_rules(): void
    {
        $open = $this->ticket(['status' => 'in_progress']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$open->id}/rating", ['rating' => 5])->assertStatus(422);

        $done = $this->ticket(['status' => 'resolved', 'assigned_agent_id' => $this->agent->id]);
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$done->id}/rating", ['rating' => 5])->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$done->id}/rating", ['rating' => 6])->assertStatus(422);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$done->id}/rating", ['rating' => 4, 'comment' => 'ok'])->assertStatus(201);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$done->id}/rating", ['rating' => 1])->assertStatus(409);

        $this->assertSame(4, $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$done->id}")->json('ticket.rating.rating'));
        $this->assertSame(4, $this->actingAs($this->agent, 'sanctum')->getJson("/api/v1/tickets/{$done->id}")->json('ticket.rating.rating'));
    }

    public function test_csat_shows_in_analytics(): void
    {
        foreach ([5, 3] as $r) {
            $t = $this->ticket(['status' => 'closed', 'assigned_agent_id' => $this->agent->id]);
            $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/rating", ['rating' => $r])->assertStatus(201);
        }

        $ov = $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/analytics/sla-overview')->assertOk();
        $this->assertEquals(4.0, $ov->json('csat_average'));
        $this->assertSame(2, $ov->json('csat_count'));

        $wl = collect($this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/analytics/agent-workload')->json('workload'))->firstWhere('agent_id', $this->agent->id);
        $this->assertEquals(4.0, $wl['csat_average']);
    }

    public function test_customer_is_asked_to_rate_when_ticket_is_resolved(): void
    {
        Notification::fake();
        $t = $this->ticket(['status' => 'in_progress', 'assigned_agent_id' => $this->agent->id]);

        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$t->id}/status", ['status' => 'resolved'])->assertOk();
        Notification::assertSentTo($this->customer, TicketResolvedNotification::class);
    }
}
