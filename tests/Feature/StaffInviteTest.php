<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\StaffInvite;
use App\Models\User;
use App\Notifications\StaffInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffInviteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
    }

    private function invite(array $over = []): array
    {
        Notification::fake();
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', array_merge([
            'name' => 'Neha Agent', 'email' => 'Neha@Example.com', 'role' => 'agent', 'department_id' => $this->dept->id,
        ], $over))->assertStatus(201);

        $token = null;
        Notification::assertSentOnDemand(StaffInviteNotification::class, function ($n) use (&$token) { $token = $n->token; return true; });

        return [$res, $token];
    }

    public function test_admin_invites_without_choosing_a_password(): void
    {
        [$res, $token] = $this->invite();

        $this->assertStringContainsString('invitation was emailed', $res->json('message'));
        $user = User::where('email', 'neha@example.com')->first();                    // email normalised
        $this->assertNull($user->email_verified_at);                                   // verified only when they accept
        $row = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users')->json('data'))->firstWhere('email', 'neha@example.com');
        $this->assertSame('pending', $row['invite_status']);                           // the team list shows "invited"
        $this->assertNull(collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users')->json('data'))->firstWhere('email', $this->admin->email)['invite_status']);

        $invite = StaffInvite::where('user_id', $user->id)->first();
        $this->assertSame(hash('sha256', $token), $invite->token_hash);               // only a hash is stored
        $this->assertStringNotContainsString($token, json_encode($invite->getAttributes()));
        $this->assertTrue($invite->expires_at->between(now()->addDays(6), now()->addDays(7)->addMinute()));
    }

    public function test_invited_person_cannot_sign_in_until_they_accept(): void
    {
        $this->invite();
        $this->postJson('/api/v1/auth/login', ['email' => 'neha@example.com', 'password' => 'anything-at-all'])->assertStatus(422);   // unknown password

        $user = User::where('email', 'neha@example.com')->first();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);                                  // reset cannot bypass the invite either
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_accepting_sets_their_own_password_signs_them_in_and_is_single_use(): void
    {
        [, $token] = $this->invite();
        $payload = ['email' => 'neha@example.com', 'token' => $token, 'password' => 'my-own-password-1', 'password_confirmation' => 'my-own-password-1'];

        $this->getJson('/api/v1/auth/invite-info?'.http_build_query(['email' => 'neha@example.com', 'token' => $token]))
            ->assertOk()->assertJsonPath('name', 'Neha Agent')->assertJsonPath('role', 'agent');

        $res = $this->postJson('/api/v1/auth/accept-invite', $payload)->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertSame('agent', $res->json('user.role'));

        $user = User::where('email', 'neha@example.com')->first();
        $this->assertTrue(Hash::check('my-own-password-1', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->postJson('/api/v1/auth/login', ['email' => 'neha@example.com', 'password' => 'my-own-password-1'])->assertOk();

        // the link is dead after use
        $this->postJson('/api/v1/auth/accept-invite', $payload)->assertStatus(422);
        $this->getJson('/api/v1/auth/invite-info?'.http_build_query(['email' => 'neha@example.com', 'token' => $token]))->assertStatus(422);
    }

    public function test_wrong_tokens_other_peoples_emails_and_expired_links_are_rejected(): void
    {
        [, $token] = $this->invite();
        $ok = ['password' => 'my-own-password-1', 'password_confirmation' => 'my-own-password-1'];

        $this->postJson('/api/v1/auth/accept-invite', ['email' => 'neha@example.com', 'token' => 'wrong-token'] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/auth/accept-invite', ['email' => $this->admin->email, 'token' => $token] + $ok)->assertStatus(422);          // token is tied to its owner
        $this->postJson('/api/v1/auth/accept-invite', ['email' => 'neha@example.com', 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);

        StaffInvite::query()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/accept-invite', ['email' => 'neha@example.com', 'token' => $token] + $ok)->assertStatus(422);
    }

    public function test_admin_can_resend_which_invalidates_the_old_link(): void
    {
        [, $old] = $this->invite();
        $user = User::where('email', 'neha@example.com')->first();

        $new = null;
        Notification::fake();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/users/{$user->id}/resend-invite")->assertOk();
        Notification::assertSentOnDemand(StaffInviteNotification::class, function ($n) use (&$new) { $new = $n->token; return true; });

        $ok = ['password' => 'my-own-password-1', 'password_confirmation' => 'my-own-password-1'];
        $this->postJson('/api/v1/auth/accept-invite', ['email' => $user->email, 'token' => $old] + $ok)->assertStatus(422);
        $this->postJson('/api/v1/auth/accept-invite', ['email' => $user->email, 'token' => $new] + $ok)->assertOk();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/users/{$user->id}/resend-invite")->assertStatus(422);   // already accepted
    }

    public function test_only_admins_can_invite_and_a_department_is_required_for_agents(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->actingAs($lead, 'sanctum')->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'agent', 'department_id' => $this->dept->id])->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'agent'])->assertStatus(422);
    }

    public function test_invite_email_is_branded_and_links_to_the_accept_page(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);
        $html = (string) (new StaffInviteNotification('Neha Agent', 'neha@example.com', 'lead', 'Infra', 'Ada <b>Admin</b>', 'tok123', 7))->toMail(new \Illuminate\Notifications\AnonymousNotifiable)->render();

        $this->assertStringContainsString('https://app.example.com/accept-invite?email=neha%40example.com&amp;token=tok123', $html);
        $this->assertStringContainsString('team lead', $html);
        $this->assertStringContainsString('cid:deskflow-logo', $html);
        $this->assertStringNotContainsString('<b>Admin</b>', $html);      // inviter name is escaped
    }
}
