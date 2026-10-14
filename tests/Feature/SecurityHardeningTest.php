<?php

namespace Tests\Feature;

use App\Models\AdminAudit;
use App\Models\Department;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_five_wrong_passwords_lock_the_account_even_for_the_right_password(): void
    {
        User::factory()->create(['email' => 'a@x.test', 'password' => Hash::make('Correct-pass-1')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@x.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'a@x.test', 'password' => 'Correct-pass-1'])
            ->assertStatus(429)->assertJsonPath('code', 'account_locked');
    }

    public function test_a_successful_login_resets_the_failure_count(): void
    {
        User::factory()->create(['email' => 'b@x.test', 'password' => Hash::make('Correct-pass-1')]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'b@x.test', 'password' => 'wrong']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'b@x.test', 'password' => 'Correct-pass-1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'b@x.test', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_short_or_letters_only_passwords_are_refused(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => Hash::make('Correct-pass-1')]);

        foreach (['short1A', 'onlylettersnonumbers'] as $bad) {
            $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/auth/password', [
                'current_password' => 'Correct-pass-1', 'password' => $bad, 'password_confirmation' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    public function test_require_two_factor_blocks_staff_without_it_but_not_the_setup_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'two_factor_secret' => 'X', 'two_factor_confirmed_at' => now()]);
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC'])->id]);
        TenantContext::get()->update(['require_two_factor' => true]);

        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/departments')->assertStatus(403);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('two_factor_setup_required', true);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/departments')->assertOk();
    }

    public function test_admin_cannot_require_two_factor_without_having_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/settings/security', ['require_two_factor' => true])
            ->assertStatus(422);
    }

    public function test_admin_changes_are_logged_without_bodies_and_reads_are_not(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', ['holiday_date' => '2027-01-26', 'name' => 'Republic Day'])->assertStatus(201);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/holidays')->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/holidays', ['name' => ''])->assertStatus(422);

        $this->assertSame(1, AdminAudit::count());
        $row = AdminAudit::first();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame('POST', $row->method);
        $this->assertSame('holidays', $row->route);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin-activity')->assertOk()
            ->assertJsonPath('data.0.route', 'holidays')->assertJsonPath('data.0.user.id', $admin->id);
    }

    public function test_non_admins_cannot_read_the_activity_log(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC'])->id]);
        $this->actingAs($lead, 'sanctum')->getJson('/api/v1/admin-activity')->assertStatus(403);
    }

    public function test_turnstile_is_enforced_only_when_a_secret_is_configured(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@x.test'])->assertStatus(202);

        config(['security.turnstile_secret' => 's']);
        \Illuminate\Support\Facades\Http::fake(['challenges.cloudflare.com/*' => \Illuminate\Support\Facades\Http::sequence()->push(['success' => false])->push(['success' => true])]);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@x.test'])->assertStatus(422)->assertJsonPath('code', 'captcha_failed');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@x.test', 'captcha_token' => 't'])->assertStatus(422); // provider said no


        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@x.test', 'captcha_token' => 't'])->assertStatus(202);
    }

    public function test_workspace_export_contains_the_workspace_data_without_password_hashes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@x.test']);
        $res = $this->actingAs($admin, 'sanctum')->get('/api/v1/settings/export');
        $res->assertOk();
        $json = json_decode($res->streamedContent(), true);

        $this->assertSame('boss@x.test', $json['users'][0]['email']);
        $this->assertArrayNotHasKey('password', $json['users'][0]);
        foreach (['tickets', 'ticket_messages', 'organizations', 'departments'] as $k) {
            $this->assertArrayHasKey($k, $json);
        }
    }

    public function test_platform_owner_can_permanently_delete_a_workspace_with_tickets_and_nothing_else(): void
    {
        $other = \App\Models\Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherUsers = \App\Tenancy\TenantContext::run($other, fn () => User::factory()->create(['role' => 'admin'])->id);

        $org = \App\Models\Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $dept->id, 'title' => 'x', 'description' => 'y'])->assertStatus(201);

        $tenant = TenantContext::get();
        $owner = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null]);

        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/platform/tenants/{$tenant->id}", ['confirm_slug' => 'wrong'])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/platform/tenants/{$tenant->id}", ['confirm_slug' => $tenant->slug])->assertOk();

        $this->assertSame(0, \DB::table('tickets')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, \DB::table('users')->where('tenant_id', $tenant->id)->count());
        $this->assertNull(\App\Models\Tenant::find($tenant->id));
        $this->assertNotNull(\DB::table('users')->find($otherUsers));
    }

    public function test_platform_health_reports_scheduler_state_and_is_owner_only(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'tenant_id' => null]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/health')->assertStatus(403);

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/platform/health')->assertOk()
            ->assertJsonPath('checks.database.ok', true)->assertJsonPath('checks.scheduler.ok', false);

        Cache::put(\App\Services\OpsHealth::HEARTBEAT, now(), 3600);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/platform/health')->assertJsonPath('checks.scheduler.ok', true);
    }
}
