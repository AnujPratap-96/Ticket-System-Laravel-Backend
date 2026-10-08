<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Support\DeviceLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
    }

    /** A real sign-in (a persistent token), the way a browser does it. */
    private function signIn(User $u, string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36'): string
    {
        return $this->withHeaders(['User-Agent' => $ua])->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret1234'])->assertOk()->json('token');
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ---- password -----------------------------------------------------------------------------

    public function test_changing_the_password_signs_out_other_devices_but_not_this_one(): void
    {
        Notification::fake();
        $u = User::factory()->create(['password' => 'secret1234', 'role' => 'customer']);
        $phone = $this->signIn($u, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1');
        $laptop = $this->signIn($u);

        $this->as($laptop)->patchJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->as($laptop)->patchJson('/api/v1/auth/password', ['current_password' => 'secret1234', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'])->assertStatus(422);   // must differ
        $this->as($laptop)->patchJson('/api/v1/auth/password', ['current_password' => 'secret1234', 'password' => 'brand-new-pass1', 'password_confirmation' => 'nope'])->assertStatus(422);

        $this->as($laptop)->patchJson('/api/v1/auth/password', ['current_password' => 'secret1234', 'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1'])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass1', $u->fresh()->password));
        $this->as($laptop)->getJson('/api/v1/auth/me')->assertOk();                  // this device stays signed in
        $this->as($phone)->getJson('/api/v1/auth/me')->assertStatus(401);            // the other one is out
        Notification::assertSentTo($u, PasswordChangedNotification::class, fn ($n) => str_contains($n->device, 'Chrome on Linux'));
    }

    public function test_password_change_requires_authentication(): void
    {
        $this->patchJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => 'brand-new-pass1', 'password_confirmation' => 'brand-new-pass1'])->assertStatus(401);
    }

    // ---- sessions -------------------------------------------------------------------------------

    public function test_sessions_list_name_the_device_and_mark_the_current_one(): void
    {
        $u = User::factory()->create(['password' => 'secret1234', 'role' => 'customer']);
        $this->signIn($u, 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0');
        $mine = $this->signIn($u);

        $list = $this->as($mine)->getJson('/api/v1/auth/sessions')->assertOk()->json('sessions');

        $this->assertCount(2, $list);
        $this->assertSame(1, collect($list)->where('current', true)->count());
        $this->assertTrue(collect($list)->contains(fn ($s) => str_starts_with($s['device'], 'Firefox on Windows')));
        $this->assertTrue(collect($list)->contains(fn ($s) => str_starts_with($s['device'], 'Chrome on Linux') && $s['current']));
    }

    public function test_a_user_can_sign_out_one_device_or_all_others_but_never_someone_elses(): void
    {
        $a = User::factory()->create(['password' => 'secret1234', 'role' => 'customer']);
        $b = User::factory()->create(['password' => 'secret1234', 'role' => 'customer']);
        $a1 = $this->signIn($a);
        $a2 = $this->signIn($a, 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0');
        $a3 = $this->signIn($a, 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari/605.1');
        $bToken = $this->signIn($b);
        $bSession = $b->tokens()->first()->id;

        $this->as($a1)->deleteJson("/api/v1/auth/sessions/{$bSession}")->assertStatus(404);        // not theirs
        $this->as($bToken)->getJson('/api/v1/auth/me')->assertOk();

        $second = $a->tokens()->where('name', 'like', 'Firefox%')->first()->id;
        $this->as($a1)->deleteJson("/api/v1/auth/sessions/{$second}")->assertOk();
        $this->as($a2)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->as($a1)->postJson('/api/v1/auth/sessions/revoke-others')->assertOk()->assertJsonPath('revoked', 1);
        $this->as($a3)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->as($a1)->getJson('/api/v1/auth/me')->assertOk();
        $this->as($bToken)->getJson('/api/v1/auth/me')->assertOk();                                  // other users untouched
    }

    public function test_device_labels(): void
    {
        $this->assertSame('Edge on Windows · 1.2.3.4', DeviceLabel::for('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Edg/120', '1.2.3.4'));
        $this->assertSame('Chrome on Android', DeviceLabel::for('Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537', null));
        $this->assertSame('Safari on iOS · 9.9.9.9', DeviceLabel::for('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit Safari/604', '9.9.9.9'));
        $this->assertSame('Unknown browser · 5.5.5.5', DeviceLabel::for(null, '5.5.5.5'));
    }

    // ---- admin erase ----------------------------------------------------------------------------

    public function test_admin_erases_a_customer_and_their_personal_text(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $c = User::factory()->create(['role' => 'customer', 'name' => 'Rahul', 'email' => 'rahul@acme.test', 'organization_id' => $this->org->id, 'is_org_admin' => true]);
        $t = Ticket::create(['ticket_number' => 'TICK-E-1', 'organization_id' => $this->org->id, 'customer_id' => $c->id, 'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'My phone is 9999999999', 'status' => 'open', 'priority' => 'medium']);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $c->id, 'body' => 'my address is 12 Hill Rd', 'is_internal_note' => false]);
        $c->createToken('x');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$c->id}/erase", ['confirm_email' => 'wrong@x.com'])->assertStatus(422);   // must type the email
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$c->id}/erase", ['confirm_email' => 'RAHUL@acme.test'])->assertOk();

        $c = $c->fresh();
        $this->assertSame('Deleted user', $c->name);
        $this->assertStringEndsWith('@deleted.invalid', $c->email);
        $this->assertFalse($c->is_active);
        $this->assertFalse($c->is_org_admin);
        $this->assertNull($c->organization_id);
        $this->assertSame(0, $c->tokens()->count());
        $this->assertStringNotContainsString('9999', $t->fresh()->description);
        $this->assertStringNotContainsString('Hill Rd', TicketMessage::first()->body);
        $this->assertSame(1, Ticket::count());                                                      // the ticket record itself stays
        $this->postJson('/api/v1/auth/login', ['email' => 'rahul@acme.test', 'password' => 'password'])->assertStatus(422);
    }

    public function test_erasing_an_agent_returns_their_tickets_to_the_pool_but_keeps_their_replies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'name' => 'Sarah']);
        $c = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $t = Ticket::create(['ticket_number' => 'TICK-E-2', 'organization_id' => $this->org->id, 'customer_id' => $c->id, 'department_id' => $this->dept->id, 'assigned_agent_id' => $agent->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium']);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $agent->id, 'body' => 'We are looking into it.', 'is_internal_note' => false]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$agent->id}/erase", ['confirm_email' => $agent->email])->assertOk();

        $this->assertNull($t->fresh()->assigned_agent_id);                                           // unassigned: someone else can claim it
        $this->assertSame('We are looking into it.', TicketMessage::first()->body);                  // company record kept
        $this->assertSame('Deleted user', $agent->fresh()->name);
        $this->assertFalse((bool) $agent->fresh()->is_available_for_routing);
    }

    public function test_erase_guards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $other = User::factory()->create(['role' => 'admin']);

        $this->actingAs($lead, 'sanctum')->postJson("/api/v1/users/{$admin->id}/erase", ['confirm_email' => $admin->email])->assertStatus(403);   // admins only
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$admin->id}/erase", ['confirm_email' => $admin->email])->assertStatus(422);  // not yourself

        $other->update(['is_active' => false]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$other->id}/erase", ['confirm_email' => $other->email])->assertOk();       // inactive admin: fine
        $second = User::factory()->create(['role' => 'admin']);
        $this->actingAs($second, 'sanctum')->postJson("/api/v1/users/{$admin->id}/erase", ['confirm_email' => $admin->email])->assertOk();
        $this->actingAs($second, 'sanctum')->postJson("/api/v1/users/{$second->id}/erase", ['confirm_email' => $second->email])->assertStatus(422);

        $this->actingAs($second, 'sanctum')->postJson("/api/v1/users/{$admin->id}/erase", ['confirm_email' => $admin->fresh()->email])->assertStatus(422);   // already erased
    }

    public function test_admin_can_find_customers_in_the_team_list_by_role_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'customer', 'email' => 'findme@acme.test']);

        $staffOnly = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/users')->json('data'))->pluck('email');
        $this->assertFalse($staffOnly->contains('findme@acme.test'));                                // default list = staff
        $customers = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/users?role=customer&search=findme')->json('data'))->pluck('email');
        $this->assertTrue($customers->contains('findme@acme.test'));
    }
}
