<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketAudit;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private Department $other;
    private User $customer;
    private User $agent;
    private User $lead;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->other = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id, 'password' => 'secret1234']);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function ticket(array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-R-'.uniqid(), 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium',
        ], $a));
    }

    public function test_trends_are_department_scoped_and_computed(): void
    {
        $t = $this->ticket(['first_responded_at' => now()->subHours(2), 'resolved_at' => now()->subHour(), 'status' => 'resolved']);
        $t->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->ticket(['department_id' => $this->other->id]);

        $lead = $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/analytics/trends?days=7')->assertOk();
        $this->assertSame(1, $lead->json('total_created'));
        $this->assertSame(60, $lead->json('avg_first_response_minutes'));
        $this->assertSame(120, $lead->json('avg_resolution_minutes'));
        $this->assertCount(7, $lead->json('series'));

        $this->assertSame(2, $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/analytics/trends')->json('total_created'));
        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/analytics/trends')->assertStatus(403);
    }

    public function test_csv_export_scopes_and_blocks_formula_injection(): void
    {
        $this->ticket(['title' => '=HYPERLINK("http://evil","x")']);
        $this->ticket(['department_id' => $this->other->id, 'title' => 'Other dept']);

        $csv = $this->actingAs($this->lead, 'sanctum')->get('/api/v1/tickets-export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Other dept', $csv);
        $this->assertStringStartsWith('Ticket,Title', $csv);

        $this->actingAs($this->agent, 'sanctum')->get('/api/v1/tickets-export')->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->get('/api/v1/tickets-export')->assertStatus(403);
    }

    public function test_global_audit_viewer_is_admin_only_and_filterable(): void
    {
        $t = $this->ticket();
        TicketAudit::create(['ticket_id' => $t->id, 'actor_id' => $this->agent->id, 'event_type' => 'status_transition', 'created_at' => now()]);
        TicketAudit::create(['ticket_id' => $t->id, 'actor_id' => $this->lead->id, 'event_type' => 'priority_changed', 'created_at' => now()]);

        $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/audits')->assertStatus(403);
        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/audits')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertCount(1, $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/audits?event_type=priority_changed')->json('data'));
        $this->assertCount(1, $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/audits?actor_id={$this->agent->id}")->json('data'));
    }

    public function test_user_can_export_own_data_without_internal_notes(): void
    {
        $t = $this->ticket(['title' => 'Mine']);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $this->customer->id, 'body' => 'hello', 'is_internal_note' => false]);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $this->agent->id, 'body' => 'secret', 'is_internal_note' => true]);

        $json = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/me/export')->assertOk()->json();
        $this->assertSame($this->customer->email, $json['profile']['email']);
        $this->assertCount(1, $json['tickets']);
        $this->assertCount(1, $json['messages']);
        $this->assertArrayNotHasKey('password', $json['profile']);
    }

    public function test_customer_can_erase_account_but_tickets_remain_anonymised(): void
    {
        $t = $this->ticket();
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $this->customer->id, 'body' => 'my phone is 12345', 'is_internal_note' => false]);
        $this->customer->createToken('x');

        $this->actingAs($this->customer, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($this->agent, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'password'])->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'secret1234'])->assertOk();

        $u = $this->customer->fresh();
        $this->assertSame('Deleted user', $u->name);
        $this->assertStringEndsWith('@deleted.invalid', $u->email);
        $this->assertFalse($u->is_active);
        $this->assertSame(0, $u->tokens()->count());
        $this->assertStringNotContainsString('12345', TicketMessage::first()->body);
        $this->assertSame(1, Ticket::count());
    }
}
