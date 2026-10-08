<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CollaborationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private Department $other;
    private User $customer;
    private User $customer2;
    private User $agent;
    private User $agent2;
    private User $lead;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->other = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->customer2 = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->agent2 = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->outsider = User::factory()->create(['role' => 'agent', 'department_id' => $this->other->id]);
    }

    private function ticket(array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-W-'.uniqid(), 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium',
        ], $a));
    }

    public function test_watchers_are_notified_and_visible_only_to_staff(): void
    {
        Notification::fake();
        $t = $this->ticket(['assigned_agent_id' => $this->agent->id]);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/watch")->assertStatus(403);
        $this->actingAs($this->outsider, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/watch")->assertStatus(403);
        $this->actingAs($this->agent2, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/watch")->assertStatus(403);   // a colleague's ticket: not visible, so not watchable
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/watch")->assertOk();

        $this->assertTrue($this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket.is_watching'));
        $this->assertArrayNotHasKey('is_watching', $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket'));

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", ['body' => 'ping'])->assertStatus(201);
        Notification::assertSentTo($this->agent, TicketActivityNotification::class);
        Notification::assertSentTo($this->lead, TicketActivityNotification::class);

        $this->actingAs($this->lead, 'sanctum')->deleteJson("/api/v1/tickets/{$t->id}/watch")->assertOk();
        $this->assertFalse($this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket.is_watching'));
    }

    public function test_a_mentioned_agent_gains_access_to_the_ticket(): void
    {
        $t = $this->ticket(['assigned_agent_id' => $this->agent->id]);
        $this->actingAs($this->agent2, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->assertStatus(403);       // not theirs

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", [
            'body' => '@agent2 can you look?', 'is_internal_note' => true, 'mentions' => [$this->agent2->id],
        ])->assertStatus(201);

        $this->actingAs($this->agent2, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->assertOk();               // now they can open it
        $ids = collect($this->actingAs($this->agent2, 'sanctum')->getJson('/api/v1/tickets')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($t->id));
    }

    public function test_mentions_notify_only_eligible_teammates_and_not_the_author(): void
    {
        Notification::fake();
        $t = $this->ticket(['assigned_agent_id' => $this->agent->id]);

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", [
            'body' => '@Lead please check', 'is_internal_note' => true,
            'mentions' => [$this->lead->id, $this->outsider->id, $this->agent->id, $this->customer->id],
        ])->assertStatus(201);

        Notification::assertSentTo($this->lead, TicketActivityNotification::class, fn ($n) => $n->kind === 'mentioned');
        Notification::assertNotSentTo($this->outsider, TicketActivityNotification::class);   // other department
        Notification::assertNotSentTo($this->agent, TicketActivityNotification::class);      // author
        Notification::assertNotSentTo($this->customer, TicketActivityNotification::class);   // not staff

        $names = collect($this->actingAs($this->agent, 'sanctum')->getJson("/api/v1/tickets/{$t->id}/mentionable")->json('users'))->pluck('id');
        $this->assertTrue($names->contains($this->lead->id));
        $this->assertFalse($names->contains($this->outsider->id));
        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$t->id}/mentionable")->assertStatus(403);
    }

    public function test_merge_moves_conversation_closes_duplicate_and_audits(): void
    {
        $keep = $this->ticket(['title' => 'Primary']);
        $dupe = $this->ticket(['title' => 'Duplicate', 'description' => 'same problem']);
        TicketSlaDeadline::create(['ticket_id' => $dupe->id, 'metric_type' => 'resolution', 'target_deadline' => now()->addDay()]);
        TicketMessage::create(['ticket_id' => $dupe->id, 'sender_id' => $this->customer->id, 'body' => 'any news?', 'is_internal_note' => false]);

        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$dupe->id}/merge", ['target_ticket_id' => $keep->id])->assertStatus(403);
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$dupe->id}/merge", ['target_ticket_id' => $dupe->id])->assertStatus(422);

        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$dupe->id}/merge", ['target_ticket_id' => $keep->id])->assertOk();

        $this->assertSame(2, TicketMessage::where('ticket_id', $keep->id)->count()); // moved + merge note
        $this->assertSame(0, TicketMessage::where('ticket_id', $dupe->id)->count());
        $this->assertSame('closed', $dupe->fresh()->status->value);
        $this->assertSame($keep->id, $dupe->fresh()->merged_into_id);
        $this->assertTrue((bool) TicketSlaDeadline::where('ticket_id', $dupe->id)->value('is_fulfilled'));
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $dupe->id, 'event_type' => 'merged_into']);
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $keep->id, 'event_type' => 'merged_from']);

        // The merge note is internal: the customer never sees it
        $visible = collect($this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$keep->id}")->json('ticket.messages'))->pluck('body');
        $this->assertTrue($visible->contains('any news?'));
        $this->assertFalse($visible->contains(fn ($b) => str_contains($b, 'Merged from')));
    }

    public function test_merge_rejects_different_customers_closed_and_already_merged(): void
    {
        $a = $this->ticket();
        $b = $this->ticket(['customer_id' => $this->customer2->id]);
        $closed = $this->ticket(['status' => 'closed']);

        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$a->id}/merge", ['target_ticket_id' => $b->id])->assertStatus(422);
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$a->id}/merge", ['target_ticket_id' => $closed->id])->assertStatus(422);

        $c = $this->ticket();
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$c->id}/merge", ['target_ticket_id' => $a->id])->assertOk();
        $d = $this->ticket();
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$d->id}/merge", ['target_ticket_id' => $c->id])->assertStatus(422); // c is closed/merged
    }
}
