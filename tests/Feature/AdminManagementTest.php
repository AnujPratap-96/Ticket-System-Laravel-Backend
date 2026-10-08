<?php

namespace Tests\Feature;

use App\Models\BusinessHoliday;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\SlaCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Department $other;
    private User $admin;
    private User $lead;
    private User $agent;
    private User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->other = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->otherAgent = User::factory()->create(['role' => 'agent', 'department_id' => $this->other->id]);
    }

    public function test_roster_is_scoped_by_role(): void
    {
        $admin = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users')->assertOk();
        $this->assertCount(4, $admin->json('data'));

        $lead = $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/users')->assertOk();
        $ids = collect($lead->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->agent->id));
        $this->assertFalse($ids->contains($this->otherAgent->id));

        $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_only_admin_can_edit_users(): void
    {
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}", ['role' => 'lead'])->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}", ['role' => 'lead'])->assertOk()->assertJsonPath('user.role', 'lead');
    }

    public function test_admin_cannot_lock_themselves_out_or_remove_last_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'agent'])->assertStatus(422);

        $second = User::factory()->create(['role' => 'admin']);
        $this->actingAs($second, 'sanctum')->patchJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])->assertOk();
        // second is now the only active admin
        $third = User::factory()->create(['role' => 'admin']);
        $this->actingAs($third, 'sanctum')->patchJson("/api/v1/users/{$second->id}", ['is_active' => false])->assertOk();
        $this->actingAs($third, 'sanctum')->patchJson("/api/v1/users/{$third->id}", ['is_active' => false])->assertStatus(422);
    }

    public function test_agent_needs_a_department(): void
    {
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}", ['department_id' => null])->assertStatus(422);
    }

    public function test_deactivated_user_loses_sessions_and_cannot_log_in(): void
    {
        $this->agent->createToken('t');
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}", ['is_active' => false])->assertOk();

        $this->assertSame(0, $this->agent->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $this->agent->email, 'password' => 'password'])->assertStatus(403)->assertJsonPath('code', 'account_inactive');
    }

    public function test_lead_can_tune_routing_only_in_own_department(): void
    {
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}/routing", ['max_active_tickets' => 3, 'is_available_for_routing' => false])
            ->assertOk()->assertJsonPath('user.max_active_tickets', 3);
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/users/{$this->otherAgent->id}/routing", ['max_active_tickets' => 3])->assertStatus(403);
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/users/{$this->agent->id}/routing", ['max_active_tickets' => 99])->assertStatus(403);
    }

    public function test_department_crud_validates_hours_and_timezone(): void
    {
        $payload = ['name' => 'Security', 'slug' => 'security', 'business_hours_start' => '08:00', 'business_hours_end' => '16:00', 'timezone' => 'Asia/Kolkata'];

        $this->actingAs($this->lead, 'sanctum')->postJson('/api/v1/departments', $payload)->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/departments', array_merge($payload, ['timezone' => 'Mars/Base']))->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/departments', array_merge($payload, ['business_hours_end' => '07:00']))->assertStatus(422);
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/departments', $payload)->assertStatus(201)->json('department.id');
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/departments/{$id}", array_merge($payload, ['name' => 'Sec Ops']))->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/departments', $payload)->assertStatus(422); // duplicate slug
    }

    public function test_holiday_crud_and_effect_on_sla(): void
    {
        $this->actingAs($this->lead, 'sanctum')->getJson('/api/v1/holidays')->assertStatus(403);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/holidays', ['holiday_date' => '2026-10-12', 'name' => 'Day Off'])->assertStatus(201)->json('holiday.id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/holidays', ['holiday_date' => '2026-10-12', 'name' => 'Dup'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/holidays')->assertOk()->assertJsonPath('holidays.0.holiday_date', '2026-10-12');

        $calc = new SlaCalculatorService();
        $d = $calc->calculateTargetTimestamp(Carbon::parse('2026-10-09 17:00:00', 'UTC'), 120, $this->dept, true);
        $this->assertSame('2026-10-13 10:00:00', $d->format('Y-m-d H:i:s')); // Monday skipped

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/holidays/{$id}")->assertOk();
        $this->assertSame(0, BusinessHoliday::count());
        $d = $calc->calculateTargetTimestamp(Carbon::parse('2026-10-09 17:00:00', 'UTC'), 120, $this->dept, true);
        $this->assertSame('2026-10-12 10:00:00', $d->format('Y-m-d H:i:s'));
    }
}
