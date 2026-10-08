<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Notifications\TicketActivityNotification;
use App\Notifications\TicketReplyNotification;
use App\Support\MarkdownSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileAndEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cloudinary.cloud_name' => 'demo', 'services.cloudinary.api_key' => 'k', 'services.cloudinary.api_secret' => 's']);
        $this->user = User::factory()->create(['role' => 'customer', 'name' => 'Rahul Sharma']);
        $this->other = User::factory()->create(['role' => 'customer']);
    }

    // ---- name -----------------------------------------------------------------------------------

    public function test_user_can_change_their_name_but_not_their_email(): void
    {
        $email = $this->user->email;
        $res = $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/auth/profile', ['name' => '  Rahul   K  Sharma ', 'email' => 'hacker@evil.test', 'role' => 'admin'])->assertOk();

        $this->assertSame('Rahul K Sharma', $res->json('user.name'));       // trimmed, spaces collapsed
        $fresh = $this->user->fresh();
        $this->assertSame($email, $fresh->email);                           // email is not editable here
        $this->assertSame('customer', $fresh->role->value);                 // nor is the role
    }

    public function test_name_is_validated_and_html_is_stripped(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $this->patchJson('/api/v1/auth/profile', ['name' => 'A'])->assertStatus(422);
        $this->patchJson('/api/v1/auth/profile', ['name' => ''])->assertStatus(422);
        $this->patchJson('/api/v1/auth/profile', ['name' => '<b></b>'])->assertStatus(422);
        $this->patchJson('/api/v1/auth/profile', ['name' => str_repeat('x', 101)])->assertStatus(422);

        $res = $this->patchJson('/api/v1/auth/profile', ['name' => 'Ravi <script>alert(1)</script>Kumar'])->assertOk();
        $this->assertStringNotContainsString('<', $res->json('user.name'));
    }

    public function test_profile_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Nope'])->assertStatus(401);
        $this->postJson('/api/v1/auth/avatar/sign')->assertStatus(401);
        $this->postJson('/api/v1/auth/avatar', ['public_id' => 'x'])->assertStatus(401);
        $this->deleteJson('/api/v1/auth/avatar')->assertStatus(401);
    }

    // ---- photo ------------------------------------------------------------------------------------

    private ?array $asset = ['bytes' => 50000, 'format' => 'jpg'];

    /** One fake whose answer is read at request time (null asset = 404), so a test can change it between calls. */
    private function fakeCloudinary(?array $asset = ['bytes' => 50000, 'format' => 'jpg']): void
    {
        $this->asset = $asset;
        Http::fake(fn ($r) => str_contains($r->url(), '/resources/image/upload/') && $r->method() === 'GET'
            ? ($this->asset === null ? Http::response([], 404) : Http::response($this->asset))
            : Http::response([]));
    }

    public function test_signing_issues_a_server_chosen_id_scoped_to_the_user(): void
    {
        $a = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar/sign')->assertOk();
        $b = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar/sign')->assertOk();

        $this->assertMatchesRegularExpression('#^deskflow/avatars/'.$this->user->id.'_[a-f0-9]{16}$#', $a->json('public_id'));
        $this->assertNotSame($a->json('public_id'), $b->json('public_id'));            // unguessable, never reused
        $this->assertSame($a->json('public_id'), $a->json('params.public_id'));
        $this->assertSame('c_limit,w_512,h_512', $a->json('params.transformation'));    // photo is downscaled on upload
        $this->assertSame(40, strlen($a->json('signature')));
        $this->assertSame(2 * 1024 * 1024, $a->json('max_bytes'));
        $this->assertArrayNotHasKey('api_secret', $a->json());
    }

    public function test_setting_a_photo_verifies_with_cloudinary_and_exposes_a_thumbnail_url(): void
    {
        $this->fakeCloudinary();
        $id = "deskflow/avatars/{$this->user->id}_".str_repeat('a1', 8);

        $res = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar', ['public_id' => $id])->assertOk();

        $this->assertStringContainsString("res.cloudinary.com/demo/image/upload/c_fill,g_face,w_160,h_160", $res->json('user.avatar_url'));
        $this->assertStringEndsWith($id, $res->json('user.avatar_url'));
        $this->assertSame($id, $this->user->fresh()->avatar_public_id);
        $this->assertStringContainsString($id, $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/auth/me')->json('user.avatar_url'));
    }

    public function test_foreign_malformed_missing_oversized_and_wrong_format_photos_are_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $mine = fn ($suffix = 'b2b2b2b2b2b2b2b2') => "deskflow/avatars/{$this->user->id}_{$suffix}";

        $this->fakeCloudinary();
        // someone else's id, another folder, traversal, wrong shape
        foreach (["deskflow/avatars/{$this->other->id}_b2b2b2b2b2b2b2b2", 'deskflow/tickets/1/x', "deskflow/avatars/{$this->user->id}_short", "deskflow/avatars/{$this->user->id}_b2b2b2b2b2b2b2b2/../x"] as $bad) {
            $this->postJson('/api/v1/auth/avatar', ['public_id' => $bad])->assertStatus(422);
        }

        $this->fakeCloudinary(['bytes' => 5 * 1024 * 1024, 'format' => 'jpg']);
        $this->postJson('/api/v1/auth/avatar', ['public_id' => $mine()])->assertStatus(422);        // too big

        $this->fakeCloudinary(['bytes' => 1000, 'format' => 'gif']);
        $this->postJson('/api/v1/auth/avatar', ['public_id' => $mine()])->assertStatus(422);        // not an allowed type

        $this->fakeCloudinary(null);
        $this->postJson('/api/v1/auth/avatar', ['public_id' => $mine()])->assertStatus(422);        // never uploaded

        $this->assertNull($this->user->fresh()->avatar_public_id);
    }

    public function test_replacing_and_removing_a_photo_deletes_the_old_asset(): void
    {
        $this->fakeCloudinary();
        $first = "deskflow/avatars/{$this->user->id}_".str_repeat('c3', 8);
        $second = "deskflow/avatars/{$this->user->id}_".str_repeat('d4', 8);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar', ['public_id' => $first])->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar', ['public_id' => $second])->assertOk();

        $deletes = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE')->map(fn ($p) => json_encode($p[0]->data(), JSON_UNESCAPED_SLASHES))->all();
        $this->assertStringContainsString($first, implode('|', $deletes));                       // old photo cleaned up

        $res = $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/auth/avatar')->assertOk();
        $this->assertNull($res->json('user.avatar_url'));
        $this->assertNull($this->user->fresh()->avatar_public_id);
        $this->assertStringContainsString($second, collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE')->map(fn ($p) => json_encode($p[0]->data(), JSON_UNESCAPED_SLASHES))->implode('|'));
    }

    public function test_avatar_is_visible_to_others_in_conversations(): void
    {
        $this->fakeCloudinary();
        $org = Organization::create(['name' => 'A', 'domain' => 'a.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
        $this->user->update(['organization_id' => $org->id]);
        $t = Ticket::create(['ticket_number' => 'TICK-P-1', 'organization_id' => $org->id, 'customer_id' => $this->user->id, 'department_id' => $dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium']);
        TicketMessage::create(['ticket_id' => $t->id, 'sender_id' => $this->user->id, 'body' => 'hi', 'is_internal_note' => false]);

        $id = "deskflow/avatars/{$this->user->id}_".str_repeat('e5', 8);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/auth/avatar', ['public_id' => $id])->assertOk();

        $sender = $this->actingAs($agent, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->json('ticket.messages.0.sender');
        $this->assertStringEndsWith($id, $sender['avatar_url']);
    }

    public function test_without_cloudinary_config_there_is_no_avatar_url_and_signing_is_refused(): void
    {
        $this->user->update(['avatar_public_id' => "deskflow/avatars/{$this->user->id}_".str_repeat('f6', 8)]);
        config(['services.cloudinary.cloud_name' => null]);

        $this->assertNull($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/auth/me')->json('user.avatar_url'));
        $this->postJson('/api/v1/auth/avatar/sign')->assertStatus(503);
    }

    // ---- email branding ---------------------------------------------------------------------------

    public function test_emails_are_branded_and_carry_the_logo_as_an_inline_attachment(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'support@example.com']);

        Notification::route('mail', 'a@example.com')->notifyNow(new OtpNotification('registration', '123456', 10));

        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        /** @var \Symfony\Component\Mime\Email $email */
        $email = $sent[0]->getOriginalMessage();
        $html = $email->getHtmlBody();

        $this->assertStringContainsString('cid:deskflow-logo', $html);          // logo is referenced...
        $inline = collect($email->getAttachments())->first(fn ($a) => $a->getPreparedHeaders()->has('content-id') || str_contains($a->asDebugString(), 'logo'));
        $this->assertNotNull($inline, 'logo PNG is embedded in the message');   // ...and actually shipped inside the email
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('Verify your email address', $html);
        $this->assertStringContainsString('#4f46e5', $html);                    // brand colour inlined
        $this->assertNotEmpty($email->getTextBody());                           // plain-text part exists too
    }

    public function test_user_written_text_is_shown_literally_in_emails(): void
    {
        $agent = new User(['name' => 'Sarah [admin](http://evil.test)', 'role' => 'agent']);
        $customer = new User(['name' => 'Rahul Sharma', 'role' => 'customer']);
        $ticket = new Ticket(['ticket_number' => 'TICK-2026-00001', 'title' => '**URGENT** click [here](http://evil.test)']);
        $ticket->id = 1;
        $msg = new TicketMessage(['body' => "Go to [your account](http://evil.test/login) now\n\n<script>alert(1)</script> **bold**"]);
        $msg->setRelation('sender', $agent);

        $html = (string) (new TicketReplyNotification($ticket, $msg))->toMail($customer)->render();

        // Staff replies may now carry formatting and links (they are our own staff), but never raw HTML.
        $this->assertStringContainsString('href="http://evil.test/login"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);                  // shown as text instead
        $this->assertStringNotContainsString('<strong>URGENT', $html);              // markdown in the subject is still not applied
        $this->assertStringNotContainsString('href="http://evil.test"', $html);      // ...nor in the agent name (text typed into a name field)
    }

    public function test_markdown_safe_escapes_control_characters(): void
    {
        $this->assertSame('\\*hi\\* \\[x\\]\\(y\\)', MarkdownSafe::escape('*hi* [x](y)'));
        $this->assertSame('a…', MarkdownSafe::escape('abcdef', 1) === 'a…' ? 'a…' : 'bad');
        $this->assertStringNotContainsString('<b>', MarkdownSafe::escape('<b>x</b>'));
    }

    public function test_email_subjects_keep_the_ticket_tag_used_for_reply_by_email(): void
    {
        $customer = new User(['name' => 'Rahul', 'role' => 'customer']);
        $ticket = new Ticket(['ticket_number' => 'TICK-2026-00042', 'title' => 'Printer down']);
        $ticket->id = 42;
        $msg = new TicketMessage(['body' => 'ok']);
        $msg->setRelation('sender', new User(['name' => 'Agent', 'role' => 'agent']));

        $this->assertSame('[TICK-2026-00042] New reply: Printer down', (new TicketReplyNotification($ticket, $msg))->toMail($customer)->subject);
    }

    public function test_staff_activity_email_links_to_the_staff_console(): void
    {
        $agent = new User(['name' => 'Sarah Connor', 'role' => 'agent']);
        config(['app.frontend_url' => 'https://app.example.com']);

        $html = (string) (new TicketActivityNotification('assigned', 7, 'TICK-2026-00007', 'Assigned', 'x'))->toMail($agent)->render();

        $this->assertStringContainsString('https://app.example.com/staff/tickets/7', $html);
        $this->assertStringContainsString('A ticket was assigned to you', $html);
    }
}
