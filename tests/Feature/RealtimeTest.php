<?php

namespace Tests\Feature;

use App\Events\TicketChanged;
use App\Events\UserPinged;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private User $customer;
    private User $stranger;
    private User $agent;
    private User $otherAgent;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => 'localhost',
        ]);
        // Channels were registered on the default (log) driver at boot; register them on the one we just switched to.
        require base_path('routes/channels.php');

        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->stranger = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->otherAgent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->ticket = Ticket::create(['ticket_number' => 'TICK-R-1', 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id, 'department_id' => $this->dept->id, 'assigned_agent_id' => $this->agent->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium']);
    }

    private function auth(User $u, string $channel)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum')->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_only_people_who_can_open_a_ticket_may_listen_to_it(): void
    {
        $this->auth($this->customer, "private-ticket.{$this->ticket->id}")->assertOk()->assertJsonStructure(['auth']);
        $this->auth($this->agent, "private-ticket.{$this->ticket->id}")->assertOk();
        $this->auth($this->stranger, "private-ticket.{$this->ticket->id}")->assertStatus(403);       // a colleague who is not company admin
        $this->auth($this->otherAgent, "private-ticket.{$this->ticket->id}")->assertStatus(403);      // agents only see their own tickets
        $this->auth($this->stranger, 'private-ticket.99999')->assertStatus(403);
    }

    public function test_customers_can_never_listen_to_the_staff_channel_or_someone_elses_inbox(): void
    {
        $this->auth($this->customer, "private-ticket.{$this->ticket->id}.staff")->assertStatus(403);
        $this->auth($this->agent, "private-ticket.{$this->ticket->id}.staff")->assertOk();
        $this->auth($this->customer, "private-user.{$this->customer->id}")->assertOk();
        $this->auth($this->customer, "private-user.{$this->agent->id}")->assertStatus(403);
    }

    public function test_the_channel_endpoint_requires_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "private-ticket.{$this->ticket->id}"])->assertStatus(401);
    }

    public function test_replies_ping_both_sides_and_internal_notes_only_staff(): void
    {
        Event::fake([TicketChanged::class]);

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", ['body' => 'We are on it', 'force_send' => true])->assertStatus(201);
        Event::assertDispatched(TicketChanged::class, fn ($e) => $e->kind === 'message' && ! $e->staffOnly && $e->ticketId === $this->ticket->id && in_array($this->customer->id, $e->userIds));

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", ['body' => 'secret', 'is_internal_note' => true, 'force_send' => true])->assertStatus(201);
        Event::assertDispatched(TicketChanged::class, function ($e) {
            $channels = array_map(fn ($c) => $c->name, $e->broadcastOn());

            return $e->kind === 'note' && $e->staffOnly && ! in_array("private-ticket.{$this->ticket->id}", $channels) && ! in_array("private-user.{$this->customer->id}", $channels);
        });
    }

    public function test_pings_never_contain_message_text(): void
    {
        $e = new TicketChanged($this->ticket->id, 'message');
        $this->assertSame(['ticket_id' => $this->ticket->id, 'kind' => 'message'], $e->broadcastWith());
    }

    public function test_status_changes_and_notifications_ping_the_right_people(): void
    {
        Event::fake([TicketChanged::class, UserPinged::class]);

        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$this->ticket->id}/status", ['status' => 'resolved'])->assertOk();
        Event::assertDispatched(TicketChanged::class, fn ($e) => $e->kind === 'status_changed');

        $this->customer->notify(new \App\Notifications\TicketResolvedNotification($this->ticket->fresh()));
        Event::assertDispatched(UserPinged::class, fn ($e) => $e->userId === $this->customer->id);
    }

    public function test_a_broken_realtime_server_never_blocks_the_action(): void
    {
        // Unreachable server: the queued broadcast fails in the background, the request itself succeeds.
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", ['body' => 'hello', 'force_send' => true])->assertStatus(201);
    }
}
