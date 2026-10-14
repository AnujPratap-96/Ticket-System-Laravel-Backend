<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Notifications\StaffInviteNotification;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keepRequestTenant = true;
        Cache::flush();
        $this->owner = TenantContext::withoutTenant(fn () => User::forceCreate([
            'tenant_id' => null, 'name' => 'Owner', 'email' => 'owner@platform.test', 'role' => UserRole::SUPER_ADMIN,
            'password' => Hash::make('secret-pass-1'), 'email_verified_at' => now(), 'is_active' => true,
        ]));
    }

    private function ownerToken(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/platform/login', ['email' => 'owner@platform.test', 'password' => 'secret-pass-1'])->assertOk()->json('token');
    }

    private function asOwner()
    {
        return $this->actingAs($this->owner, 'sanctum');
    }

    public function test_the_platform_owner_signs_in_separately_from_workspaces(): void
    {
        $this->postJson('/api/v1/platform/login', ['email' => 'owner@platform.test', 'password' => 'wrong'])->assertStatus(422);
        $this->assertNotEmpty($this->ownerToken());

        // ...and the same credentials do nothing in a workspace's sign-in
        $this->withHeader('X-Tenant', 'default')->postJson('/api/v1/auth/login', ['email' => 'owner@platform.test', 'password' => 'secret-pass-1'])->assertStatus(422);

        // a workspace admin cannot use the platform login
        TenantContext::run(Tenant::where('slug', 'default')->first(), fn () => User::factory()->create(['role' => 'admin', 'email' => 'boss@co.test', 'password' => 'secret-pass-1']));
        $this->postJson('/api/v1/platform/login', ['email' => 'boss@co.test', 'password' => 'secret-pass-1'])->assertStatus(422);
    }

    public function test_platform_routes_are_for_the_owner_only_and_the_owner_cannot_open_workspace_data(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();
        $admin = TenantContext::run($tenant, fn () => User::factory()->create(['role' => 'admin']));

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/tenants')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/platform/tenants', [])->assertStatus(401);

        $this->asOwner()->getJson('/api/v1/platform/tenants')->assertOk();
        $this->asOwner()->getJson('/api/v1/tickets')->assertStatus(403)->assertJsonPath('code', 'platform_account');
        $this->asOwner()->getJson('/api/v1/users')->assertStatus(403);
        $this->asOwner()->getJson('/api/v1/auth/me')->assertStatus(403);
    }

    public function test_the_overview_counts_but_never_shows_workspace_content(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();
        TenantContext::run($tenant, function () {
            $org = \App\Models\Organization::create(['name' => 'O', 'domain' => 'o.test', 'sla_tier' => 'gold', 'is_active' => true]);
            $dept = \App\Models\Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC']);
            $c = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id, 'email' => 'secret.person@o.test']);
            User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
            Ticket::create(['ticket_number' => 'TICK-P-1', 'organization_id' => $org->id, 'customer_id' => $c->id, 'department_id' => $dept->id, 'title' => 'Top secret title', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);
        });

        $res = $this->asOwner()->getJson('/api/v1/platform/tenants')->assertOk();
        $row = collect($res->json('tenants'))->firstWhere('slug', 'default');
        $this->assertSame([1, 1, 1], [$row['tickets'], $row['customers'], $row['staff']]);
        $this->assertStringNotContainsString('secret', json_encode($res->json()));
        $this->assertStringNotContainsString('Top secret', json_encode($res->json()));
    }

    public function test_the_owner_creates_a_workspace_and_its_first_admin_is_invited(): void
    {
        Notification::fake();

        $res = $this->asOwner()->postJson('/api/v1/platform/tenants', ['name' => 'Gamma Help Desk', 'admin_name' => 'Gita Admin', 'admin_email' => 'gita@gamma.test'])->assertStatus(201);
        $this->assertSame('gamma-help-desk', $res->json('tenant.slug'));                       // address suggested from the name

        $tenant = Tenant::where('slug', 'gamma-help-desk')->first();
        TenantContext::run($tenant, function () {
            $admin = User::where('email', 'gita@gamma.test')->first();
            $this->assertSame('admin', $admin->role->value);
            $this->assertNull($admin->email_verified_at);                                   // verified when the invitation is accepted
            $this->assertSame(['General Support'], \App\Models\Department::pluck('name')->all());
        });
        Notification::assertSentOnDemand(StaffInviteNotification::class, fn ($n) => $n->email === 'gita@gamma.test' && str_contains($n->tenant->url('/x'), 'gamma-help-desk'));
    }

    public function test_workspace_addresses_are_validated(): void
    {
        $this->asOwner();
        $ok = ['name' => 'X Co', 'admin_name' => 'Ann', 'admin_email' => 'ann@x.test'];

        foreach (['www', 'platform', 'api'] as $reserved) {
            $this->postJson('/api/v1/platform/tenants', $ok + ['slug' => $reserved])->assertStatus(422);
        }
        $this->postJson('/api/v1/platform/tenants', $ok + ['slug' => 'Has Spaces'])->assertStatus(422);
        $this->postJson('/api/v1/platform/tenants', $ok + ['slug' => '-bad-'])->assertStatus(422);
        $this->postJson('/api/v1/platform/tenants', $ok + ['slug' => 'default'])->assertStatus(422);   // taken
    }

    public function test_the_owner_can_suspend_and_restore_a_workspace(): void
    {
        $tenant = Tenant::where('slug', 'default')->first();
        $this->asOwner()->patchJson("/api/v1/platform/tenants/{$tenant->id}", ['is_active' => false])->assertOk();
        $this->assertNotNull($tenant->fresh()->suspended_at);
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant', 'default')->getJson('/api/v1/kb/articles')->assertStatus(403)->assertJsonPath('code', 'workspace_suspended');

        $this->asOwner()->patchJson("/api/v1/platform/tenants/{$tenant->id}", ['is_active' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Tenant', 'default')->getJson('/api/v1/kb/articles')->assertOk();
    }

    // ---------------- self-service sign-up ----------------

    public function test_a_company_creates_its_own_workspace_with_an_emailed_code(): void
    {
        Notification::fake();
        $body = ['workspace_name' => 'Delta Desk', 'slug' => 'delta', 'admin_name' => 'Dev Admin', 'email' => 'dev@delta.test', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'];
        $code = null;

        $this->postJson('/api/v1/workspaces/register', $body)->assertStatus(202);
        $this->assertNull(Tenant::where('slug', 'delta')->first());                          // nothing exists until the code is entered
        Notification::assertSentOnDemand(OtpNotification::class, function ($n) use (&$code) { $code = $n->code; return true; });

        $this->postJson('/api/v1/workspaces/verify', ['email' => 'dev@delta.test', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/workspaces/verify', ['email' => 'dev@delta.test', 'code' => $code])->assertStatus(201)->assertJsonPath('tenant.slug', 'delta');

        $res = $this->withHeader('X-Tenant', 'delta')->postJson('/api/v1/auth/login', ['email' => 'dev@delta.test', 'password' => 'secret1234'])->assertOk();
        $this->assertSame('admin', $res->json('user.role'));
        $this->assertSame('Delta Desk', $res->json('user.tenant.name'));
    }

    public function test_sign_up_refuses_taken_reserved_or_disabled(): void
    {
        $body = ['workspace_name' => 'Zed', 'slug' => 'default', 'admin_name' => 'Zed', 'email' => 'z@z.test', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'];
        $this->postJson('/api/v1/workspaces/register', $body)->assertStatus(422);
        $this->postJson('/api/v1/workspaces/register', ['slug' => 'admin'] + $body)->assertStatus(422);
        $this->postJson('/api/v1/workspaces/register', ['slug' => 'zed', 'password_confirmation' => 'nope'] + $body)->assertStatus(422);

        config(['tenancy.self_signup' => false]);
        $this->postJson('/api/v1/workspaces/register', ['slug' => 'zed'] + $body)->assertStatus(403);
    }

    public function test_anyone_can_check_an_address_and_find_a_workspaces_name(): void
    {
        $this->getJson('/api/v1/workspaces/available?slug=brand-new')->assertOk()->assertJsonPath('available', true);
        $this->getJson('/api/v1/workspaces/available?slug=default')->assertOk()->assertJsonPath('available', false);
        $this->getJson('/api/v1/workspaces/available?slug=www')->assertOk()->assertJsonPath('available', false);

        $this->getJson('/api/v1/workspaces/default')->assertOk()->assertJsonStructure(['name', 'slug'])->assertJsonMissingPath('id');
        $this->getJson('/api/v1/workspaces/nope')->assertStatus(404);
    }
}
