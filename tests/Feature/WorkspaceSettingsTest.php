<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private array $A;
    private array $B;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keepRequestTenant = true;
        Cache::flush();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
        $this->A = $this->world($this->a, 'alpha');
        $this->B = $this->world($this->b, 'beta');
        TenantContext::set(null);
    }

    private function world(Tenant $t, string $tag): array
    {
        return TenantContext::run($t, function () use ($tag) {
            $dept = Department::create(['name' => "Dept {$tag}", 'slug' => 'support', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
            $org = Organization::create(['name' => "Org {$tag}", 'domain' => 'client.test', 'sla_tier' => 'gold', 'is_active' => true]);
            $admin = User::factory()->create(['role' => 'admin', 'email' => "admin@{$tag}.test"]);
            $agent = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
            $customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id, 'email' => 'shared@client.test']);

            return compact('dept', 'org', 'admin', 'agent', 'customer');
        });
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    // ---------------- reply-by-email channel ----------------

    public function test_each_workspace_has_its_own_mail_secret_and_cannot_post_into_another(): void
    {
        $secretA = $this->as($this->A['admin'])->postJson('/api/v1/settings/email-channel/enable')->assertOk()->json('secret');
        $secretB = $this->as($this->B['admin'])->postJson('/api/v1/settings/email-channel/enable')->assertOk()->json('secret');
        $this->assertNotSame($secretA, $secretB);

        $mail = ['from' => 'shared@client.test', 'subject' => 'Printer broken', 'text' => 'Please help'];
        $this->app['auth']->forgetGuards();

        // alpha's secret works for alpha...
        $this->postJson('/api/v1/webhooks/inbound-email?tenant=alpha', $mail, ['X-Webhook-Secret' => $secretA])->assertStatus(202)->assertJsonPath('result', 'ticket_created');
        // ...and is useless against beta, even though alpha's admin knows it
        $this->postJson('/api/v1/webhooks/inbound-email?tenant=beta', $mail, ['X-Webhook-Secret' => $secretA])->assertStatus(401);

        $count = fn (Tenant $t) => TenantContext::run($t, fn () => Ticket::count());
        $this->assertSame(1, $count($this->a));
        $this->assertSame(0, $count($this->b));
    }

    public function test_the_secret_is_shown_once_and_stored_encrypted(): void
    {
        $res = $this->as($this->A['admin'])->postJson('/api/v1/settings/email-channel/enable')->assertOk();
        $secret = $res->json('secret');
        $this->assertStringContainsString("tenant=alpha&secret={$secret}", $res->json('webhook_url'));

        $state = $this->as($this->A['admin'])->getJson('/api/v1/settings/email-channel')->assertOk();
        $this->assertTrue($state->json('enabled'));
        $this->assertStringNotContainsString($secret, json_encode($state->json()));
        $this->assertStringNotContainsString($secret, (string) \DB::table('tenants')->where('slug', 'alpha')->value('inbound_secret'));
    }

    public function test_rotating_the_secret_cuts_off_the_old_one_and_disabling_turns_the_channel_off(): void
    {
        $old = $this->as($this->A['admin'])->postJson('/api/v1/settings/email-channel/enable')->json('secret');
        $new = $this->as($this->A['admin'])->postJson('/api/v1/settings/email-channel/enable')->json('secret');
        $mail = ['from' => 'shared@client.test', 'subject' => 'Hello', 'text' => 'body'];
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/webhooks/inbound-email?tenant=alpha', $mail, ['X-Webhook-Secret' => $old])->assertStatus(401);
        $this->postJson('/api/v1/webhooks/inbound-email?tenant=alpha', $mail + ['message_id' => 'm1'], ['X-Webhook-Secret' => $new])->assertStatus(202);

        $this->as($this->A['admin'])->deleteJson('/api/v1/settings/email-channel')->assertOk()->assertJsonPath('enabled', false);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/webhooks/inbound-email?tenant=alpha', $mail, ['X-Webhook-Secret' => $new])->assertStatus(503);
    }

    public function test_new_email_tickets_go_to_the_workspaces_chosen_department(): void
    {
        $sales = TenantContext::run($this->a, fn () => Department::create(['name' => 'Sales', 'slug' => 'sales', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']));
        $secret = $this->as($this->A['admin'])->postJson('/api/v1/settings/email-channel/enable')->json('secret');
        $this->as($this->A['admin'])->patchJson('/api/v1/settings/email-channel', ['department_id' => $sales->id])->assertOk()->assertJsonPath('department_id', $sales->id);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/webhooks/inbound-email?tenant=alpha', ['from' => 'shared@client.test', 'subject' => 'Pricing?', 'text' => 'hi'], ['X-Webhook-Secret' => $secret])->assertStatus(202);

        $this->assertSame($sales->id, TenantContext::run($this->a, fn () => Ticket::first()->department_id));
    }

    public function test_a_department_from_another_workspace_is_refused_and_only_admins_may_configure(): void
    {
        $this->as($this->A['admin'])->patchJson('/api/v1/settings/email-channel', ['department_id' => $this->B['dept']->id])->assertStatus(422);

        $this->as($this->A['agent'])->getJson('/api/v1/settings/email-channel')->assertStatus(403);
        $this->as($this->A['customer'])->postJson('/api/v1/settings/email-channel/enable')->assertStatus(403);
    }

    // ---------------- branding ----------------

    public function test_a_workspace_sets_its_own_brand_without_touching_another(): void
    {
        $this->as($this->A['admin'])->patchJson('/api/v1/settings/branding', ['name' => 'Alpha Help Desk', 'brand_color' => '#E11D48', 'sender_name' => 'Alpha Support', 'reply_to_email' => 'help@alpha.test'])
            ->assertOk()->assertJsonPath('brand_color', '#e11d48')->assertJsonPath('name', 'Alpha Help Desk');

        $this->assertSame('Beta', $this->b->fresh()->name);
        $this->assertNull($this->b->fresh()->brand_color);
        $this->as($this->B['admin'])->getJson('/api/v1/settings/branding')->assertOk()->assertJsonPath('name', 'Beta')->assertJsonPath('brand_color', null);
    }

    public function test_brand_input_is_validated_and_admin_only(): void
    {
        $this->as($this->A['admin']);
        $this->patchJson('/api/v1/settings/branding', ['brand_color' => 'red'])->assertStatus(422);
        $this->patchJson('/api/v1/settings/branding', ['brand_color' => '#12345'])->assertStatus(422);
        $this->patchJson('/api/v1/settings/branding', ['reply_to_email' => 'nope'])->assertStatus(422);
        $this->patchJson('/api/v1/settings/branding', ['name' => 'x'])->assertStatus(422);
        $this->patchJson('/api/v1/settings/branding', ['sender_name' => '<script>alert(1)</script>Acme'])->assertOk()->assertJsonPath('sender_name', 'alert(1)Acme');

        $this->as($this->A['agent'])->patchJson('/api/v1/settings/branding', ['name' => 'Mine now'])->assertStatus(403);
        $this->as($this->A['customer'])->getJson('/api/v1/settings/branding')->assertStatus(403);
    }

    public function test_emails_carry_the_workspaces_name_colour_sender_and_logo(): void
    {
        config(['services.cloudinary.cloud_name' => 'democloud', 'services.cloudinary.api_key' => 'k', 'services.cloudinary.api_secret' => 's', 'mail.from.address' => 'noreply@desk.test']);
        $this->a->update(['name' => 'Alpha Help Desk', 'brand_color' => '#e11d48', 'sender_name' => 'Alpha Support', 'reply_to_email' => 'help@alpha.test', 'logo_public_id' => 'deskflow/logos/1_abc']);

        $mail = (new \App\Notifications\PasswordChangedNotification('Chrome on Linux'))->toMail($this->A['customer']->fresh());
        $html = (string) $mail->render();

        $this->assertSame('Your Alpha Help Desk password was changed', $mail->subject);
        $this->assertSame(['noreply@desk.test', 'Alpha Support'], $mail->from);
        $this->assertSame([['help@alpha.test', 'Alpha Help Desk']], $mail->replyTo);
        $this->assertStringContainsString('#e11d48', $html);
        $this->assertStringContainsString('https://res.cloudinary.com/democloud/image/upload/', $html);
        $this->assertStringContainsString('Alpha Help Desk', $html);
        $this->assertStringNotContainsString('cid:deskflow-logo', $html);
        $this->assertStringNotContainsString('Beta', $html);

        // the other workspace is untouched: default logo, default colour, its own name
        $other = (string) (new \App\Notifications\PasswordChangedNotification('x'))->toMail($this->B['customer']->fresh())->render();
        $this->assertStringContainsString('cid:deskflow-logo', $other);
        $this->assertStringContainsString('Beta', $other);
        $this->assertStringNotContainsString('#e11d48', $other);
    }

    public function test_the_public_workspace_lookup_exposes_only_brand_basics(): void
    {
        $this->a->update(['brand_color' => '#0ea5e9']);
        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/v1/workspaces/alpha')->assertOk();
        $this->assertSame(['name', 'slug', 'brand_color', 'logo_url'], array_keys($res->json()));
        $this->assertSame('#0ea5e9', $res->json('brand_color'));
    }

    public function test_a_logo_can_only_be_one_issued_to_this_workspace_and_is_verified_with_cloudinary(): void
    {
        config(['services.cloudinary.cloud_name' => 'democloud', 'services.cloudinary.api_key' => 'k', 'services.cloudinary.api_secret' => 's']);

        $sign = $this->as($this->A['admin'])->postJson('/api/v1/settings/branding/logo/sign')->assertOk();
        $this->assertMatchesRegularExpression('#^deskflow/logos/'.$this->a->id.'_[a-f0-9]{16}$#', $sign->json('public_id'));

        // another workspace's (or an arbitrary) reference is refused before Cloudinary is even asked
        $this->as($this->A['admin'])->postJson('/api/v1/settings/branding/logo', ['public_id' => 'deskflow/logos/'.$this->b->id.'_0123456789abcdef'])->assertStatus(422);
        $this->as($this->A['admin'])->postJson('/api/v1/settings/branding/logo', ['public_id' => 'deskflow/avatars/1_0123456789abcdef'])->assertStatus(422);

        \Illuminate\Support\Facades\Http::fake(['api.cloudinary.com/*' => \Illuminate\Support\Facades\Http::response(['bytes' => 12345, 'format' => 'png'], 200)]);
        $ok = $sign->json('public_id');
        $this->as($this->A['admin'])->postJson('/api/v1/settings/branding/logo', ['public_id' => $ok])->assertOk();
        $this->assertSame($ok, $this->a->fresh()->logo_public_id);

        $this->as($this->A['admin'])->deleteJson('/api/v1/settings/branding/logo')->assertOk()->assertJsonPath('logo_url', null);
        $this->assertNull($this->a->fresh()->logo_public_id);
    }
}
