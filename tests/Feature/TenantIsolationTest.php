<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\AutomationRule;
use App\Models\BusinessHoliday;
use App\Models\CannedResponse;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\OtpNotification;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The promise of multi-tenancy: one workspace can never see, change or even detect another's data.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;
    private array $A = [];
    private array $B = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keepRequestTenant = true;
        Cache::flush();

        // 'default' + two workspaces -> more than one workspace exists, so the "only one" shortcut is off
        $this->a = Tenant::create(['name' => 'Alpha Support', 'slug' => 'alpha']);
        $this->b = Tenant::create(['name' => 'Beta Support', 'slug' => 'beta']);
        $this->A = $this->world($this->a, 'alpha');
        $this->B = $this->world($this->b, 'beta');
        TenantContext::set(null);
    }

    /** One small, complete workspace. */
    private function world(Tenant $t, string $tag): array
    {
        return TenantContext::run($t, function () use ($tag) {
            $dept = Department::create(['name' => "Dept {$tag}", 'slug' => 'support', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
            $org = Organization::create(['name' => "Org {$tag}", 'domain' => 'client.test', 'sla_tier' => 'gold', 'is_active' => true]);
            $admin = User::factory()->create(['role' => 'admin', 'email' => "admin@{$tag}.test", 'password' => 'secret1234']);
            $agent = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id, 'email' => "agent@{$tag}.test"]);
            $customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id, 'email' => 'shared@client.test', 'password' => 'secret1234', 'name' => "Customer of {$tag}"]);
            $ticket = Ticket::create(['ticket_number' => "TICK-{$tag}-1", 'organization_id' => $org->id, 'customer_id' => $customer->id, 'department_id' => $dept->id, 'assigned_agent_id' => $agent->id, 'title' => "Secret of {$tag}", 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);
            $article = Article::create(['title' => "Article {$tag}", 'slug' => 'welcome', 'body' => "Only for {$tag}", 'is_published' => true]);
            $canned = CannedResponse::create(['title' => "Canned {$tag}", 'body' => 'x', 'scope' => 'global', 'owner_id' => $admin->id]);
            $hook = WebhookEndpoint::create(['name' => "Hook {$tag}", 'type' => 'generic', 'url' => 'https://93.184.216.34/h', 'secret' => 's', 'events' => ['ticket.created'], 'is_active' => true]);
            $rule = AutomationRule::create(['name' => "Rule {$tag}", 'trigger' => 'ticket_created', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'value' => $tag]], 'is_active' => true, 'created_by' => $admin->id]);
            BusinessHoliday::create(['holiday_date' => '2027-01-01', 'name' => "Holiday {$tag}"]);

            return compact('dept', 'org', 'admin', 'agent', 'customer', 'ticket', 'article', 'canned', 'hook', 'rule');
        });
    }

    private function as(User $u)
    {
        return $this->actingAs($u, 'sanctum');
    }

    // ---------------- which workspace am I in? ----------------

    public function test_the_same_email_can_exist_in_two_workspaces_and_sign_in_is_per_workspace(): void
    {
        $this->assertSame($this->A['customer']->email, $this->B['customer']->email);

        $res = $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/login', ['email' => 'shared@client.test', 'password' => 'secret1234'])->assertOk();
        $this->assertSame($this->A['customer']->id, $res->json('user.id'));
        $this->assertSame('Alpha Support', $res->json('user.tenant.name'));

        $res = $this->withHeader('X-Tenant', 'beta')->postJson('/api/v1/auth/login', ['email' => 'shared@client.test', 'password' => 'secret1234'])->assertOk();
        $this->assertSame($this->B['customer']->id, $res->json('user.id'));

        // An alpha admin cannot sign in to beta, whatever the password
        $this->withHeader('X-Tenant', 'beta')->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => 'secret1234'])->assertStatus(422);
    }

    public function test_public_requests_must_say_which_workspace_when_there_are_several(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'shared@client.test', 'password' => 'secret1234'])->assertStatus(400)->assertJsonPath('code', 'workspace_required');
        $this->getJson('/api/v1/kb/articles')->assertStatus(400);
        $this->withHeader('X-Tenant', 'nowhere')->getJson('/api/v1/kb/articles')->assertStatus(404)->assertJsonPath('code', 'workspace_not_found');
    }

    public function test_with_exactly_one_workspace_it_is_chosen_automatically(): void
    {
        Tenant::whereIn('slug', ['default', 'beta'])->update(['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'shared@client.test', 'password' => 'secret1234'])->assertOk()->assertJsonPath('user.id', $this->A['customer']->id);
    }

    public function test_a_signed_in_person_stays_in_their_own_workspace_whatever_the_request_says(): void
    {
        $token = $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => 'secret1234'])->json('token');
        $this->app['auth']->forgetGuards();

        $res = $this->withToken($token)->withHeader('X-Tenant', 'beta')->getJson('/api/v1/tickets')->assertOk();
        $this->assertSame(['TICK-alpha-1'], array_column($res->json('data'), 'ticket_number'));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/tickets?tenant=beta')->assertOk()->assertJsonPath('data.0.ticket_number', 'TICK-alpha-1');
    }

    // ---------------- tickets ----------------

    public function test_an_admin_sees_only_their_workspaces_tickets_and_cannot_open_anothers_by_id(): void
    {
        $this->as($this->A['admin'])->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ticket_number', 'TICK-alpha-1');

        $other = $this->B['ticket'];
        $this->as($this->A['admin'])->getJson("/api/v1/tickets/{$other->id}")->assertStatus(404);
        $this->as($this->A['admin'])->patchJson("/api/v1/tickets/{$other->id}/status", ['status' => 'in_progress'])->assertStatus(404);
        $this->as($this->A['admin'])->patchJson("/api/v1/tickets/{$other->id}/priority", ['priority' => 'urgent'])->assertStatus(404);
        $this->as($this->A['admin'])->postJson("/api/v1/tickets/{$other->id}/messages", ['body' => 'hi'])->assertStatus(404);
        $this->as($this->A['admin'])->getJson("/api/v1/tickets/{$other->id}/audits")->assertStatus(404);
        $this->as($this->A['admin'])->putJson("/api/v1/tickets/{$other->id}/tags", ['tags' => ['x']])->assertStatus(404);
        $this->as($this->A['admin'])->postJson("/api/v1/tickets/{$other->id}/watch")->assertStatus(404);

        $this->assertSame('open', $other->fresh()->status->value);
        $this->assertSame('medium', $other->fresh()->priority->value);
    }

    public function test_customers_never_reach_another_workspaces_tickets_even_with_the_same_email(): void
    {
        $this->as($this->A['customer'])->getJson('/api/v1/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.ticket_number', 'TICK-alpha-1');
        $this->as($this->A['customer'])->getJson("/api/v1/tickets/{$this->B['ticket']->id}")->assertStatus(404);
    }

    public function test_references_to_another_workspaces_records_are_rejected(): void
    {
        $this->as($this->A['customer'])->postJson('/api/v1/tickets', ['department_id' => $this->B['dept']->id, 'title' => 'x', 'description' => 'y'])->assertStatus(422)->assertJsonValidationErrors('department_id');
        $this->as($this->A['admin'])->postJson('/api/v1/users', ['name' => 'N', 'email' => 'n@alpha.test', 'role' => 'agent', 'department_id' => $this->B['dept']->id])->assertStatus(422);
        $this->as($this->A['admin'])->patchJson("/api/v1/tickets/{$this->A['ticket']->id}/assign", ['agent_id' => $this->B['agent']->id])->assertStatus(422);
        $this->as($this->A['admin'])->postJson("/api/v1/tickets/{$this->A['ticket']->id}/merge", ['target_ticket_id' => $this->B['ticket']->id])->assertStatus(422);
        $this->assertNull(TenantContext::withoutTenant(fn () => Ticket::withoutGlobalScopes()->find($this->B['ticket']->id)->merged_into_id));
    }

    public function test_bulk_actions_ignore_other_workspaces_tickets(): void
    {
        $res = $this->as($this->A['admin'])->postJson('/api/v1/tickets/bulk', ['ids' => [$this->A['ticket']->id, $this->B['ticket']->id], 'action' => 'priority', 'priority' => 'urgent'])->assertOk();
        $this->assertSame([$this->A['ticket']->id], $res->json('updated'));
        $this->assertSame($this->B['ticket']->id, $res->json('failed.0.id'));
        $this->assertSame('medium', TenantContext::withoutTenant(fn () => Ticket::withoutGlobalScopes()->find($this->B['ticket']->id)->priority->value));
    }

    // ---------------- everything else in the workspace ----------------

    public function test_every_list_is_limited_to_the_workspace(): void
    {
        $this->as($this->A['admin']);

        $this->assertSame(['admin@alpha.test', 'agent@alpha.test'], collect($this->getJson('/api/v1/users?per_page=100')->json('data'))->pluck('email')->sort()->values()->all());
        $this->assertSame(['Org alpha'], array_column($this->getJson('/api/v1/organizations')->json('organizations'), 'name'));
        $this->assertSame(['Dept alpha'], array_column($this->getJson('/api/v1/departments')->json('departments'), 'name'));
        $this->assertSame(['Canned alpha'], array_column($this->getJson('/api/v1/canned-responses')->json('canned_responses'), 'title'));
        $this->assertSame(['Hook alpha'], array_column($this->getJson('/api/v1/webhooks')->json('webhooks'), 'name'));
        $this->assertSame(['Rule alpha'], array_column($this->getJson('/api/v1/automation-rules')->json('rules'), 'name'));
        $this->assertSame(['Holiday alpha'], array_column($this->getJson('/api/v1/holidays')->json('holidays'), 'name'));
        $this->assertSame(['Article alpha'], array_column($this->getJson('/api/v1/kb-manage/articles')->json('articles'), 'title'));

        $this->assertSame(1, $this->getJson('/api/v1/analytics/sla-overview')->json('total_sla_tracked') >= 0 ? 1 : 0);
        $this->assertSame(1, array_sum($this->getJson('/api/v1/analytics/sla-overview')->json('tickets_summary')));
        $this->assertSame(['Org alpha'], array_column($this->getJson('/api/v1/usage')->json('organizations'), 'name'));
        $this->assertStringNotContainsString('beta', json_encode($this->getJson('/api/v1/audits')->json()));
        $this->assertStringNotContainsString('beta', $this->get('/api/v1/tickets-export')->streamedContent());
    }

    public function test_records_of_another_workspace_cannot_be_changed_or_deleted_by_id(): void
    {
        $this->as($this->A['admin']);

        $this->patchJson("/api/v1/users/{$this->B['agent']->id}", ['name' => 'Hacked'])->assertStatus(404);
        $this->deleteJson("/api/v1/users/{$this->B['agent']->id}")->assertStatus(404);
        $this->patchJson("/api/v1/organizations/{$this->B['org']->id}", ['name' => 'X', 'domain' => 'x.test', 'sla_tier' => 'gold'])->assertStatus(404);
        $this->deleteJson("/api/v1/organizations/{$this->B['org']->id}")->assertStatus(404);
        $this->patchJson("/api/v1/departments/{$this->B['dept']->id}", ['name' => 'X', 'slug' => 'x', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC'])->assertStatus(404);
        $this->deleteJson("/api/v1/webhooks/{$this->B['hook']->id}")->assertStatus(404);
        $this->postJson("/api/v1/webhooks/{$this->B['hook']->id}/test")->assertStatus(404);
        $this->deleteJson("/api/v1/automation-rules/{$this->B['rule']->id}")->assertStatus(404);
        $this->patchJson("/api/v1/kb-manage/articles/{$this->B['article']->id}", ['title' => 'x', 'body' => 'y', 'is_published' => true])->assertStatus(404);
        $this->deleteJson("/api/v1/canned-responses/{$this->B['canned']->id}")->assertStatus(404);

        TenantContext::withoutTenant(function () {
            $this->assertSame('Hook beta', WebhookEndpoint::withoutGlobalScopes()->find($this->B['hook']->id)->name);
            $this->assertNotNull(User::withoutGlobalScopes()->find($this->B['agent']->id));
        });
    }

    public function test_uniqueness_is_per_workspace(): void
    {
        $this->as($this->A['admin']);

        // "support" department slug, the org domain, a holiday date and an email already exist in BOTH workspaces: that is fine...
        $this->postJson('/api/v1/departments', ['name' => 'Second', 'slug' => 'second', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC'])->assertStatus(201);
        // ...but inside one workspace the rules still hold
        $this->postJson('/api/v1/departments', ['name' => 'Dup', 'slug' => 'support', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC'])->assertStatus(422);
        $this->postJson('/api/v1/organizations', ['name' => 'Dup', 'domain' => 'client.test', 'sla_tier' => 'gold'])->assertStatus(422);
        $this->postJson('/api/v1/holidays', ['holiday_date' => '2027-01-01', 'name' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/v1/users', ['name' => 'D', 'email' => 'shared@client.test', 'role' => 'customer', 'password' => 'secret1234'])->assertStatus(422);
        $this->postJson('/api/v1/users', ['name' => 'D', 'email' => 'admin@beta.test', 'role' => 'agent', 'department_id' => $this->A['dept']->id])->assertStatus(201);   // an address used only in another workspace
    }

    public function test_workspace_settings_are_independent(): void
    {
        TenantContext::run($this->a, fn () => \App\Models\AppSetting::put('ai_enabled', '0'));
        $this->assertSame('0', TenantContext::run($this->a, fn () => \App\Models\AppSetting::get('ai_enabled', '1')));
        $this->assertSame('1', TenantContext::run($this->b, fn () => \App\Models\AppSetting::get('ai_enabled', '1')));
    }

    // ---------------- public help centre ----------------

    public function test_the_public_help_centre_shows_only_the_requested_workspaces_articles(): void
    {
        $a = $this->withHeader('X-Tenant', 'alpha')->getJson('/api/v1/kb/articles')->assertOk()->json('articles');
        $b = $this->withHeader('X-Tenant', 'beta')->getJson('/api/v1/kb/articles')->assertOk()->json('articles');
        $this->assertSame(['Article alpha'], array_column($a, 'title'));
        $this->assertSame(['Article beta'], array_column($b, 'title'));

        // same slug in both: each workspace gets its own
        $this->withHeader('X-Tenant', 'alpha')->getJson('/api/v1/kb/articles/welcome')->assertOk()->assertJsonPath('article.body', 'Only for alpha');
        $this->withHeader('X-Tenant', 'beta')->getJson('/api/v1/kb/articles/welcome')->assertOk()->assertJsonPath('article.body', 'Only for beta');
    }

    // ---------------- sign-up codes and invitations ----------------

    public function test_sign_up_codes_are_per_workspace(): void
    {
        Notification::fake();
        $body = ['name' => 'New Person', 'email' => 'newbie@client.test', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'];
        $code = null;

        $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/register', $body)->assertStatus(202);
        Notification::assertSentOnDemand(OtpNotification::class, function ($n) use (&$code) { $code = $n->code; return true; });

        // the code issued for alpha is worthless in beta
        $this->withHeader('X-Tenant', 'beta')->postJson('/api/v1/auth/verify-otp', ['email' => 'newbie@client.test', 'code' => $code])->assertStatus(422);
        $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/verify-otp', ['email' => 'newbie@client.test', 'code' => $code])->assertStatus(201);

        $created = TenantContext::withoutTenant(fn () => User::withoutGlobalScopes()->where('email', 'newbie@client.test')->get());
        $this->assertSame([$this->a->id], $created->pluck('tenant_id')->all());
        $this->assertSame($this->A['org']->id, $created->first()->organization_id);   // joined alpha's own matching organization
    }

    // ---------------- suspending a workspace ----------------

    public function test_a_suspended_workspace_is_locked_for_everyone_in_it_only(): void
    {
        $token = $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => 'secret1234'])->json('token');
        $this->a->update(['is_active' => false]);

        $this->withHeader('X-Tenant', 'alpha')->postJson('/api/v1/auth/login', ['email' => 'admin@alpha.test', 'password' => 'secret1234'])->assertStatus(403)->assertJsonPath('code', 'workspace_suspended');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/tickets')->assertStatus(403)->assertJsonPath('code', 'workspace_suspended');

        $this->app['auth']->forgetGuards();
        $this->as($this->B['admin'])->getJson('/api/v1/tickets')->assertOk();                     // beta is untouched
    }

    // ---------------- background work ----------------

    public function test_scheduled_automation_only_touches_each_workspaces_own_tickets(): void
    {
        TenantContext::run($this->a, fn () => $this->A['rule']->update(['trigger' => 'idle', 'idle_hours' => 1]));
        Ticket::withoutGlobalScopes()->update(['updated_at' => now()->subHours(5)]);

        $this->artisan('automation:run-idle')->assertSuccessful();

        $tagsOf = fn (Tenant $t, Ticket $ticket) => TenantContext::run($t, fn () => Ticket::find($ticket->id)->tags()->pluck('name')->all());
        $this->assertSame(['alpha'], $tagsOf($this->a, $this->A['ticket']));
        $this->assertSame([], $tagsOf($this->b, $this->B['ticket']));                              // beta's ticket was idle too, but alpha's rule is not beta's
    }

    public function test_events_reach_only_the_ticket_owners_webhooks(): void
    {
        Queue::fake();

        // as the scheduler would: no workspace set at all
        TenantContext::set(null);
        app(\App\Services\TicketEvents::class)->publish('ticket.created', Ticket::withoutGlobalScopes()->find($this->A['ticket']->id));

        Queue::assertPushed(\App\Jobs\DeliverWebhookJob::class, 1);
        Queue::assertPushed(\App\Jobs\DeliverWebhookJob::class, fn ($j) => $j->endpointId === $this->A['hook']->id);
    }

    public function test_emails_link_to_the_right_workspace(): void
    {
        config(['app.frontend_url' => 'https://app.example.com', 'tenancy.base_domain' => null]);
        $this->assertSame('https://app.example.com/portal?tenant=alpha', $this->a->url('/portal'));

        config(['tenancy.base_domain' => 'deskflow.example.com']);
        $this->assertSame('https://alpha.deskflow.example.com/portal', $this->a->url('/portal'));
    }

    public function test_the_workspace_can_come_from_the_address(): void
    {
        config(['tenancy.base_domain' => 'deskflow.test']);
        $this->getJson('http://beta.deskflow.test/api/v1/kb/articles')->assertOk()->assertJsonPath('articles.0.title', 'Article beta');
    }

    // ---------------- realtime ----------------

    public function test_live_channels_cannot_be_joined_across_workspaces(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => '1', 'broadcasting.connections.reverb.options.host' => 'localhost']);
        require base_path('routes/channels.php');

        $join = fn (User $u, string $ch) => $this->as($u)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $ch])->status();

        $this->assertSame(200, $join($this->A['admin'], "private-ticket.{$this->A['ticket']->id}"));
        $this->assertSame(403, $join($this->A['admin'], "private-ticket.{$this->B['ticket']->id}"));
        $this->assertSame(403, $join($this->A['admin'], "private-ticket.{$this->B['ticket']->id}.staff"));
    }
}
