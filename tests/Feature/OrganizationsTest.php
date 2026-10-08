<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $lead;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
    }

    private function ticketFor(User $customer, array $a = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-O-'.uniqid(), 'organization_id' => $customer->organization_id ?? 1, 'customer_id' => $customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium',
        ], $a));
    }

    public function test_only_admins_manage_organizations(): void
    {
        $payload = ['name' => 'Acme', 'domain' => 'acme.com', 'sla_tier' => 'gold'];
        $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/organizations')->assertStatus(403);
        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/organizations', $payload)->assertStatus(403);
        $this->actingAs(User::factory()->create(['role' => 'customer']), 'sanctum')->getJson('/api/v1/organizations')->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/organizations', $payload)->assertStatus(201)->assertJsonPath('organization.domain', 'acme.com');
    }

    public function test_domain_is_normalised_and_validated(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/organizations', ['name' => 'Acme', 'domain' => ' HTTPS://WWW.Acme.COM/pricing ', 'sla_tier' => 'gold'])
            ->assertStatus(201)->assertJsonPath('organization.domain', 'acme.com');

        $this->postJson('/api/v1/organizations', ['name' => 'Dup', 'domain' => 'acme.com', 'sla_tier' => 'gold'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/organizations', ['name' => 'Bad', 'domain' => 'not a domain', 'sla_tier' => 'gold'])->assertStatus(422);
        $this->postJson('/api/v1/organizations', ['name' => 'Bad', 'domain' => 'localhost', 'sla_tier' => 'gold'])->assertStatus(422);
        $this->postJson('/api/v1/organizations', ['name' => 'Bad', 'domain' => 'x.com', 'sla_tier' => 'diamond'])->assertStatus(422);
    }

    public function test_updating_the_plan_changes_the_sla_tier_used_for_new_tickets(): void
    {
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.com', 'sla_tier' => 'standard', 'is_active' => true]);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/organizations/{$org->id}", ['name' => 'Acme Corp', 'domain' => 'acme.com', 'sla_tier' => 'platinum', 'is_active' => true])
            ->assertOk()->assertJsonPath('organization.sla_tier', 'platinum');
        $this->assertSame('platinum', $org->fresh()->sla_tier->value);
    }

    public function test_customers_who_signed_up_earlier_can_be_attached_by_domain(): void
    {
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.com', 'sla_tier' => 'gold', 'is_active' => true]);
        $match = User::factory()->create(['role' => 'customer', 'email' => 'bob@acme.com', 'organization_id' => null]);
        $lookalike = User::factory()->create(['role' => 'customer', 'email' => 'eve@notacme.com', 'organization_id' => null]);
        $taken = User::factory()->create(['role' => 'customer', 'email' => 'zed@acme.com', 'organization_id' => Organization::create(['name' => 'Other', 'domain' => 'other.com', 'sla_tier' => 'silver', 'is_active' => true])->id]);
        $staff = User::factory()->create(['role' => 'agent', 'email' => 'sam@acme.com', 'department_id' => $this->dept->id, 'organization_id' => null]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/organizations/{$org->id}/attach-customers")->assertOk()->assertJsonPath('attached', 1);

        $this->assertSame($org->id, $match->fresh()->organization_id);
        $this->assertNull($lookalike->fresh()->organization_id);          // "notacme.com" must not match "acme.com"
        $this->assertNotSame($org->id, $taken->fresh()->organization_id);  // never steals a customer from another company
        $this->assertNull($staff->fresh()->organization_id);               // staff are not customers
    }

    public function test_company_admin_sees_and_answers_colleagues_tickets_but_nobody_elses(): void
    {
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.com', 'sla_tier' => 'gold', 'is_active' => true]);
        $other = Organization::create(['name' => 'Rival', 'domain' => 'rival.com', 'sla_tier' => 'gold', 'is_active' => true]);
        $boss = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $colleague = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $stranger = User::factory()->create(['role' => 'customer', 'organization_id' => $other->id]);

        $mine = $this->ticketFor($boss);
        $theirs = $this->ticketFor($colleague);
        $foreign = $this->ticketFor($stranger);

        // Before the flag: strictly own tickets
        $this->actingAs($boss, 'sanctum')->getJson("/api/v1/tickets/{$theirs->id}")->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/organizations/{$org->id}/customers/{$boss->id}", ['is_org_admin' => true])->assertOk();
        $boss = $boss->fresh();                                                                // actingAs() uses the in-memory model

        $ids = fn ($qs = '') => collect($this->actingAs($boss, 'sanctum')->getJson('/api/v1/tickets'.$qs)->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$mine->id, $theirs->id], $ids());                                   // whole company
        $this->assertSame([$mine->id], $ids('?scope=mine'));                                   // can switch back to just theirs
        $this->actingAs($boss, 'sanctum')->getJson("/api/v1/tickets/{$theirs->id}")->assertOk();
        $this->actingAs($boss, 'sanctum')->getJson("/api/v1/tickets/{$foreign->id}")->assertStatus(403);   // never another company
        $this->actingAs($boss, 'sanctum')->postJson("/api/v1/tickets/{$theirs->id}/messages", ['body' => 'Following up for my colleague'])->assertStatus(201);

        // The colleague still only sees their own, and cannot rate or act as the owner on someone else's behalf
        $this->assertSame([$theirs->id], collect($this->actingAs($colleague, 'sanctum')->getJson('/api/v1/tickets')->json('data'))->pluck('id')->all());
        $theirs->update(['status' => 'resolved']);
        $this->actingAs($boss, 'sanctum')->postJson("/api/v1/tickets/{$theirs->id}/rating", ['rating' => 5])->assertStatus(403);   // only the owner rates
    }

    public function test_company_admin_flag_only_applies_inside_the_same_organization(): void
    {
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.com', 'sla_tier' => 'gold', 'is_active' => true]);
        $outsider = User::factory()->create(['role' => 'customer', 'organization_id' => null]);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/organizations/{$org->id}/customers/{$outsider->id}", ['is_org_admin' => true])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/organizations/{$org->id}/customers/{$this->lead->id}", ['is_org_admin' => true])->assertStatus(422);   // staff
    }

    public function test_listing_includes_counts_and_is_searchable(): void
    {
        $org = Organization::create(['name' => 'Acme Corp', 'domain' => 'acme.com', 'sla_tier' => 'gold', 'is_active' => true]);
        Organization::create(['name' => 'Zeta', 'domain' => 'zeta.io', 'sla_tier' => 'silver', 'is_active' => true]);
        $c = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $this->ticketFor($c);

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/organizations')->assertOk()->json('organizations');
        $acme = collect($all)->firstWhere('domain', 'acme.com');
        $this->assertSame(1, $acme['customers_count']);
        $this->assertSame(1, $acme['tickets_count']);

        $found = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/organizations?search=zeta')->json('organizations');
        $this->assertSame(['zeta.io'], array_column($found, 'domain'));
    }
}
