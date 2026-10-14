<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LimitsAndFixesTest extends TestCase
{
    use RefreshDatabase;

    private Department $infra;
    private Department $billing;
    private Organization $org;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $mk = fn ($n, $s, $tz) => Department::create(['name' => $n, 'slug' => $s, 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => $tz]);
        $this->infra = $mk('Infra', 'infra', 'UTC');
        $this->billing = $mk('Billing', 'billing', 'Asia/Kolkata');
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'standard', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
    }

    private function ticket(User $c, array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-L-'.uniqid(), 'organization_id' => $c->organization_id ?? $this->org->id, 'customer_id' => $c->id,
            'department_id' => $this->infra->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium',
        ], $a));
    }

    private function file(User $c)
    {
        return $this->actingAs($c, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->infra->id, 'title' => 'Help', 'description' => 'please']);
    }

    // ---------------- fixes ----------------

    public function test_deleting_an_organization_with_tickets_needs_a_destination_and_moves_them(): void
    {
        $other = Organization::create(['name' => 'Other', 'domain' => 'other.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $t = $this->ticket($this->customer);
        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson("/api/v1/organizations/{$this->org->id}")->assertStatus(422)->assertJsonFragment(['message' => 'This organization has 1 ticket. Choose another organization to move them to, then delete it.']);
        $this->deleteJson("/api/v1/organizations/{$this->org->id}?move_to={$this->org->id}")->assertStatus(422);
        $this->assertNotNull(Organization::find($this->org->id));

        $this->deleteJson("/api/v1/organizations/{$this->org->id}?move_to={$other->id}")->assertOk();
        $this->assertNull(Organization::find($this->org->id));
        $this->assertSame($other->id, $t->fresh()->organization_id);
        $this->assertNull($this->customer->fresh()->organization_id);           // people are unlinked, not deleted
        $this->assertFalse((bool) $this->customer->fresh()->is_org_admin);
    }

    public function test_an_organization_without_tickets_just_deletes(): void
    {
        $empty = Organization::create(['name' => 'Empty', 'domain' => 'empty.test', 'sla_tier' => 'standard', 'is_active' => true]);
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/organizations/{$empty->id}")->assertOk();
        $this->assertNull(Organization::find($empty->id));
    }

    public function test_removing_a_user_deletes_when_unused_and_suspends_when_there_is_history(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $fresh = User::factory()->create(['role' => 'agent', 'department_id' => $this->infra->id]);
        $this->deleteJson("/api/v1/users/{$fresh->id}")->assertOk()->assertJsonPath('outcome', 'deleted');
        $this->assertNull(User::find($fresh->id));

        $used = User::factory()->create(['role' => 'agent', 'department_id' => $this->infra->id, 'name' => 'Sarah Real', 'email' => 'sarah@real.test']);
        $t = $this->ticket($this->customer, ['assigned_agent_id' => $used->id]);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $used->id, 'body' => 'We are on it', 'is_internal_note' => false]);   // a reply = history
        $this->deleteJson("/api/v1/users/{$used->id}")->assertOk()->assertJsonPath('outcome', 'suspended');
        $used->refresh();
        $this->assertFalse($used->is_active);
        $this->assertSame('Sarah Real', $used->name);                             // history keeps the real name
        $this->assertNull($t->fresh()->assigned_agent_id);                        // ticket back to the pool

        // ...and the account can be brought back
        $this->patchJson("/api/v1/users/{$used->id}", ['is_active' => true])->assertOk();
        $this->assertTrue($used->fresh()->is_active);
    }

    public function test_a_customer_erasing_their_own_account_is_really_anonymised(): void
    {
        $c = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id, 'password' => 'secret1234', 'name' => 'Rahul', 'email' => 'rahul@acme.test']);
        $t = $this->ticket($c, ['description' => 'my phone is 99999']);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $c->id, 'body' => 'my address is here', 'is_internal_note' => false]);

        $this->actingAs($c, 'sanctum')->deleteJson('/api/v1/me', ['password' => 'secret1234'])->assertOk();

        $c->refresh();
        $this->assertSame('Deleted user', $c->name);
        $this->assertStringEndsWith('@deleted.invalid', $c->email);
        $this->assertStringNotContainsString('99999', $t->fresh()->description);
        $this->assertStringNotContainsString('address', TicketMessage::first()->body);
    }

    public function test_assigning_across_departments_moves_the_ticket_recalculates_sla_audits_and_tells_the_new_leads(): void
    {
        // Fixed clock: 12:00 UTC is mid-afternoon in Infra (UTC) but 17:30 in Billing (Kolkata), so the two departments give different targets at any real time of day.
        \Illuminate\Support\Carbon::setTestNow('2026-10-14 12:00:00 UTC');
        Notification::fake();
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->billing->id]);
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->billing->id]);
        $t = $this->file($this->customer)->assertStatus(201)->json('ticket.id');
        $before = Ticket::find($t)->slaDeadlines()->first()->target_deadline;

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/tickets/{$t}/assign", ['agent_id' => $agent->id])->assertOk();

        $ticket = Ticket::find($t);
        $this->assertSame($this->billing->id, $ticket->department_id);
        $this->assertSame($agent->id, $ticket->assigned_agent_id);
        $this->assertNotEquals($before, $ticket->slaDeadlines()->first()->fresh()->target_deadline);       // new timezone, new clock
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $t, 'event_type' => 'department_changed', 'old_value' => 'Infra', 'new_value' => 'Billing']);
        Notification::assertSentTo($lead, \App\Notifications\TicketActivityNotification::class, fn ($n) => $n->kind === 'moved');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_failed_assignment_does_not_leave_the_ticket_in_the_new_department(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->billing->id, 'max_active_tickets' => 1]);
        $this->ticket($this->customer, ['department_id' => $this->billing->id, 'assigned_agent_id' => $agent->id]);   // agent is full
        $t = $this->ticket($this->customer);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/tickets/{$t->id}/assign", ['agent_id' => $agent->id])->assertStatus(409);
        $this->assertSame($this->infra->id, $t->fresh()->department_id);
    }

    public function test_logins_expire_after_eight_hours_unless_explicitly_disabled(): void
    {
        $this->assertSame(480, config('sanctum.expiration'));
    }

    // ---------------- limits ----------------

    public function test_monthly_ticket_limit_blocks_with_a_clear_message_and_resets_each_month(): void
    {
        $this->org->update(['monthly_ticket_limit' => 2]);
        $this->file($this->customer)->assertStatus(201);
        $this->file($this->customer)->assertStatus(201);

        $this->file($this->customer)->assertStatus(422)->assertJsonPath('code', 'quota_exceeded')->assertJsonPath('limit', 'monthly_tickets')
            ->assertJsonFragment(['message' => "Your company's plan allows 2 tickets per month and that limit has been reached. It resets on the 1st; ask your administrator or support to raise it."]);

        Ticket::query()->update(['created_at' => now()->subMonth()]);
        $this->file($this->customer)->assertStatus(201);
    }

    public function test_open_tickets_per_customer_are_capped_and_resolving_frees_a_slot(): void
    {
        $this->org->update(['max_open_per_customer' => 2]);
        $a = $this->ticket($this->customer);
        $this->ticket($this->customer);

        $this->file($this->customer)->assertStatus(422)->assertJsonPath('limit', 'open_per_customer');
        $other = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->file($other)->assertStatus(201);                                  // someone else is unaffected

        $a->update(['status' => 'resolved']);
        $this->file($this->customer)->assertStatus(201);
    }

    public function test_zero_means_unlimited_and_empty_means_plan_default_and_the_shared_org_is_exempt(): void
    {
        $this->org->update(['monthly_ticket_limit' => 0, 'max_open_per_customer' => 0]);
        foreach (range(1, 4) as $i) { $this->ticket($this->customer); }
        $this->file($this->customer)->assertStatus(201);

        $shared = Organization::create(['name' => 'Default', 'domain' => 'default.local', 'sla_tier' => 'standard', 'is_active' => true]);
        $limits = app(\App\Services\PlanLimits::class);
        $this->assertNull($limits->for($shared)['monthly_tickets']);
        $this->assertNull($limits->for($shared)['customers']);
        $this->assertSame(100, app(\App\Services\PlanLimits::class)->for(Organization::create(['name' => 'N', 'domain' => 'n.test', 'sla_tier' => 'standard', 'is_active' => true]))['monthly_tickets']);
    }

    public function test_seat_limit_blocks_signup_assignment_by_admin_and_bulk_attach(): void
    {
        $this->org->update(['max_customers' => 1]);          // the existing customer already fills it

        $this->postJson('/api/v1/auth/register', ['name' => 'New Person', 'email' => 'new@acme.test', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'])
            ->assertStatus(422)->assertJsonPath('limit', 'customers');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@acme.test', 'role' => 'customer', 'organization_id' => $this->org->id, 'password' => 'secret1234'])
            ->assertStatus(422)->assertJsonPath('limit', 'customers');

        $stray = User::factory()->create(['role' => 'customer', 'email' => 'stray@acme.test', 'organization_id' => null]);
        $res = $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/organizations/{$this->org->id}/attach-customers")->assertOk();
        $this->assertSame(0, $res->json('attached'));
        $this->assertSame(1, $res->json('not_added'));
        $this->assertNull($stray->fresh()->organization_id);

        $this->org->update(['max_customers' => 5]);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/organizations/{$this->org->id}/attach-customers")->assertOk()->assertJsonPath('attached', 1);
    }

    public function test_admin_sets_overrides_and_sees_limits_and_usage(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $res = $this->patchJson("/api/v1/organizations/{$this->org->id}", ['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'standard', 'is_active' => true, 'monthly_ticket_limit' => 7, 'max_customers' => 3, 'max_open_per_customer' => null])->assertOk();
        $this->assertSame(7, $res->json('organization.limits.monthly_tickets'));
        $this->assertSame(10, $res->json('organization.limits.open_per_customer'));      // plan default
        $this->assertNull($res->json('organization.overrides.max_open_per_customer'));

        $this->ticket($this->customer);
        $usage = $this->getJson('/api/v1/usage')->assertOk();
        $row = collect($usage->json('organizations'))->firstWhere('id', $this->org->id);
        $this->assertSame(1, $row['usage']['tickets_this_month']);
        $this->assertSame(33, $row['percent']);                                          // the fullest meter: 1 of 3 customers (tickets are 1 of 7)

        $this->patchJson("/api/v1/organizations/{$this->org->id}", ['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'standard', 'monthly_ticket_limit' => -1])->assertStatus(422);
    }

    public function test_usage_is_admin_only_and_customers_see_their_own_plan_limits(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->infra->id]);
        $this->actingAs($lead, 'sanctum')->getJson('/api/v1/usage')->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/usage')->assertStatus(403);

        $me = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/me/sla')->assertOk();
        $this->assertSame(100, $me->json('limits.plan.monthly_tickets'));
        $this->assertSame(0, $me->json('limits.usage.tickets_this_month'));
    }

    public function test_creating_tickets_and_messages_is_rate_limited_per_person(): void
    {
        $this->org->update(['monthly_ticket_limit' => 0, 'max_open_per_customer' => 0]);
        foreach (range(1, 5) as $i) { $this->file($this->customer)->assertStatus(201); }
        $this->file($this->customer)->assertStatus(429);                                        // 5 per minute
        $other = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->file($other)->assertStatus(201);                                                 // another person is not affected

        $t = Ticket::first();
        foreach (range(1, 20) as $i) {
            $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", ['body' => "m{$i}"])->assertStatus(201);
        }
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", ['body' => 'one too many'])->assertStatus(429);
    }

    public function test_only_the_newest_devices_stay_signed_in(): void
    {
        $u = User::factory()->create(['role' => 'customer', 'password' => 'secret1234', 'organization_id' => $this->org->id]);
        config(['plans.global.sessions_per_user' => 3]);
        foreach (range(1, 8) as $i) {
            $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/120'])->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret1234'])->assertOk();
        }
        $this->assertSame(3, $u->tokens()->count());
    }

    public function test_platform_caps_on_webhooks_rules_and_saved_replies(): void
    {
        config(['plans.global.webhooks' => 1, 'plans.global.automation_rules' => 1, 'plans.global.canned_responses_per_user' => 1]);
        $this->actingAs($this->admin, 'sanctum');

        $hook = ['name' => 'Ops', 'type' => 'generic', 'url' => 'https://93.184.216.34/h', 'events' => ['ticket.created']];
        $this->postJson('/api/v1/webhooks', $hook)->assertStatus(201);
        $this->postJson('/api/v1/webhooks', $hook)->assertStatus(422);

        $rule = ['name' => 'R1', 'trigger' => 'ticket_created', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'value' => 'x']]];
        $this->postJson('/api/v1/automation-rules', $rule)->assertStatus(201);
        $this->postJson('/api/v1/automation-rules', $rule)->assertStatus(422);

        $canned = ['title' => 'Hi', 'body' => 'Hello', 'scope' => 'personal'];
        $this->postJson('/api/v1/canned-responses', $canned)->assertStatus(201);
        $this->postJson('/api/v1/canned-responses', $canned)->assertStatus(422);
    }

    public function test_email_tickets_over_the_limit_are_dropped_not_crashed(): void
    {
        $this->org->update(['monthly_ticket_limit' => 1]);
        $this->ticket($this->customer);
        \App\Models\Tenant::where('slug', 'default')->first()->update(['inbound_secret' => 'sekret', 'inbound_department_id' => $this->infra->id]);

        $res = $this->postJson('/api/v1/webhooks/inbound-email', ['from' => $this->customer->email, 'subject' => 'Help me', 'text' => 'body'], ['X-Inbound-Secret' => 'sekret', 'Authorization' => 'Bearer sekret']);
        $this->assertContains($res->status(), [200, 202, 401, 403, 422]);            // never a 500
        $this->assertSame(1, Ticket::count());
    }
}
