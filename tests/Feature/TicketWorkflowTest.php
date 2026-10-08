<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private User $agent;
    private Department $dept;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Acme Test Org',
            'domain' => 'testacme.com',
            'sla_tier' => 'platinum',
            'is_active' => true,
        ]);

        $this->dept = Department::create([
            'name' => 'Cloud Support',
            'slug' => 'cloud-support',
            'business_hours_start' => '09:00:00',
            'business_hours_end' => '18:00:00',
            'timezone' => 'UTC',
        ]);

        $this->customer = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => 'customer',
        ]);

        $this->agent = User::factory()->create([
            'department_id' => $this->dept->id,
            'role' => 'agent',
            'is_available_for_routing' => true,
            'max_active_tickets' => 5,
        ]);
    }

    public function test_customer_can_create_ticket_with_sla_and_auto_routing(): void
    {
        $response = $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/tickets', [
                'department_id' => $this->dept->id,
                'title' => 'Kubernetes pod crashloop backoff',
                'description' => 'Node cluster ran out of memory.',
                'priority' => 'high',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('ticket.title', 'Kubernetes pod crashloop backoff')
            ->assertJsonPath('ticket.priority', 'high')
            ->assertJsonPath('ticket.assigned_agent.id', $this->agent->id); // Workload auto-assigned to agent

        $this->assertDatabaseHas('tickets', [
            'title' => 'Kubernetes pod crashloop backoff',
            'assigned_agent_id' => $this->agent->id,
        ]);

        // SLA deadlines were computed and attached
        $this->assertDatabaseHas('ticket_sla_deadlines', [
            'metric_type' => 'first_response',
        ]);
    }

    public function test_fsm_prevents_illegal_state_transition(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TICK-TEST-001',
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id,
            'title' => 'Test FSM',
            'description' => 'Test body',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        // From 'open', jumping directly to 'resolved' is prohibited (must go through in_progress)
        $response = $this->actingAs($this->agent, 'sanctum')
            ->patchJson("/api/v1/tickets/{$ticket->id}/status", [
                'status' => 'resolved',
            ]);

        $response->assertStatus(422)->assertJsonPath('code', 'invalid_state_transition');
    }

    public function test_customer_cannot_see_internal_staff_notes(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TICK-TEST-002',
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id,
            'title' => 'Internal Note Privacy Test',
            'description' => 'Test body',
            'status' => 'in_progress',
        ]);

        // Agent posts public message
        $this->actingAs($this->agent, 'sanctum')
            ->postJson("/api/v1/tickets/{$ticket->id}/messages", [
                'body' => 'Public answer visible to customer.',
                'is_internal_note' => false,
            ])->assertStatus(201);

        // Agent posts confidential staff note
        $this->actingAs($this->agent, 'sanctum')
            ->postJson("/api/v1/tickets/{$ticket->id}/messages", [
                'body' => 'CONFIDENTIAL: Customer might have breached fair use quota.',
                'is_internal_note' => true,
            ])->assertStatus(201);

        // Customer views ticket
        $customerView = $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertStatus(200);

        $messages = $customerView->json('ticket.messages');
        $this->assertCount(1, $messages); // Only public message returned!
        $this->assertEquals('Public answer visible to customer.', $messages[0]['body']);
    }

    public function test_agent_collision_warning_ping(): void
    {
        $ticket = Ticket::create([
            'ticket_number' => 'TICK-TEST-003',
            'organization_id' => $this->org->id,
            'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id,
            'title' => 'Collision Test',
            'description' => 'Test body',
        ]);

        $secondAgent = User::factory()->create([
            'department_id' => $this->dept->id,
            'role' => 'agent',
        ]);

        // Agent 1 pings presence
        $this->actingAs($this->agent, 'sanctum')
            ->postJson("/api/v1/tickets/{$ticket->id}/presence", ['action' => 'typing'])
            ->assertStatus(200);

        // Agent 2 opens ticket -> sees Agent 1 as active collision!
        $agent2View = $this->actingAs($secondAgent, 'sanctum')
            ->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertStatus(200);

        $collisions = $agent2View->json('active_collisions');
        $this->assertNotEmpty($collisions);
        $this->assertEquals($this->agent->id, $collisions[0]['agent_id']);
    }
}
