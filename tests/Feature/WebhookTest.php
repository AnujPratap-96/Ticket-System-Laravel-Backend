<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhookJob;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_URL = 'https://93.184.216.34/hooks/abc123';

    private User $admin;
    private User $customer;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
    }

    // ---- SSRF guard ---------------------------------------------------------------------------

    public function test_private_internal_and_odd_addresses_are_refused(): void
    {
        foreach (['https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://192.168.1.1/x', 'https://172.16.0.1/x', 'https://169.254.169.254/latest/meta-data', 'https://100.64.0.1/x', 'https://0.0.0.0/x', 'https://[::1]/x', 'https://[::ffff:127.0.0.1]/x', 'https://[fe80::1]/x', 'https://[fd00::1]/x'] as $url) {
            $this->assertIsString(SafeUrl::resolve($url), "{$url} should be refused");
        }
        $this->assertIsString(SafeUrl::resolve('http://93.184.216.34/x'));                    // plain http
        $this->assertIsString(SafeUrl::resolve('https://user:pw@93.184.216.34/x'));           // credentials
        $this->assertIsString(SafeUrl::resolve('ftp://93.184.216.34/x'));
        $this->assertIsString(SafeUrl::resolve('not a url'));
        $this->assertIsString(SafeUrl::resolve('https://93.184.216.34:99999/x'));
    }

    public function test_a_public_address_is_accepted_and_pinned(): void
    {
        $this->assertSame(['host' => '93.184.216.34', 'port' => 443, 'ip' => '93.184.216.34', 'scheme' => 'https'], SafeUrl::resolve(self::PUBLIC_URL));
        $this->assertSame(8443, SafeUrl::resolve('https://93.184.216.34:8443/x')['port']);
    }

    public function test_a_name_is_refused_if_any_of_its_addresses_is_private(): void
    {
        $this->assertIsString(SafeUrl::resolve('https://evil.example/x', fn () => ['93.184.216.34', '10.0.0.1']));    // mixed answer
        $this->assertIsString(SafeUrl::resolve('https://localhost.example/x', fn () => ['127.0.0.1']));
        $this->assertIsString(SafeUrl::resolve('https://nowhere.example/x', fn () => []));
        $this->assertSame('93.184.216.34', SafeUrl::resolve('https://good.example/x', fn () => ['93.184.216.34'])['ip']);
    }

    public function test_the_api_rejects_internal_urls_when_saving(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/webhooks', ['name' => 'Evil', 'type' => 'generic', 'url' => 'https://169.254.169.254/latest', 'events' => ['ticket.created']])
            ->assertStatus(422);
        $this->assertSame(0, WebhookEndpoint::count());
    }

    // ---- management ---------------------------------------------------------------------------

    public function test_admin_manages_webhooks_and_secrets_are_not_leaked(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $res = $this->postJson('/api/v1/webhooks', ['name' => 'Ops', 'type' => 'generic', 'url' => self::PUBLIC_URL, 'events' => ['ticket.created', 'ticket.created', 'sla.breached']])->assertStatus(201);

        $this->assertSame(40, strlen($res->json('secret')));                                  // shown once
        $this->assertSame(['ticket.created', 'sla.breached'], $res->json('webhook.events'));
        $this->assertArrayNotHasKey('url', $res->json('webhook'));
        $this->assertStringNotContainsString('abc123', json_encode($this->getJson('/api/v1/webhooks')->json()));
        $this->assertStringNotContainsString($res->json('secret'), json_encode($this->getJson('/api/v1/webhooks')->json()));
        $this->assertNotSame(self::PUBLIC_URL, \DB::table('webhook_endpoints')->value('url'));    // encrypted at rest

        $id = $res->json('webhook.id');
        $this->patchJson("/api/v1/webhooks/{$id}", ['name' => 'Renamed', 'events' => ['ticket.assigned']])->assertOk()->assertJsonPath('webhook.name', 'Renamed');
        $this->assertSame(self::PUBLIC_URL, WebhookEndpoint::find($id)->url);                  // blank url keeps the old one
        $this->postJson('/api/v1/webhooks', ['name' => 'X', 'type' => 'generic', 'url' => self::PUBLIC_URL, 'events' => ['nope']])->assertStatus(422);
        $this->deleteJson("/api/v1/webhooks/{$id}")->assertOk();
    }

    public function test_only_admins(): void
    {
        $lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
        $this->actingAs($lead, 'sanctum')->getJson('/api/v1/webhooks')->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/webhooks', [])->assertStatus(403);
    }

    // ---- delivery -----------------------------------------------------------------------------

    private function hook(string $type = 'generic', array $events = ['ticket.created']): WebhookEndpoint
    {
        return WebhookEndpoint::create(['name' => 'H', 'type' => $type, 'url' => self::PUBLIC_URL, 'secret' => $type === 'generic' ? 'topsecret' : null, 'events' => $events, 'is_active' => true]);
    }

    private function file()
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => 'Printer <b>on fire</b>', 'description' => 'secret description text']);
    }

    public function test_generic_delivery_is_signed_and_minimal(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $hook = $this->hook();

        $this->file()->assertStatus(201);

        Http::assertSent(function ($r) {
            $ts = $r->header('X-DeskFlow-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), 'topsecret');
            $data = $r->data();

            return $r->url() === self::PUBLIC_URL
                && $r->header('X-DeskFlow-Signature')[0] === $expected
                && $r->header('X-DeskFlow-Event')[0] === 'ticket.created'
                && $data['event'] === 'ticket.created'
                && ! str_contains($r->body(), 'secret description text')                      // no message bodies
                && ! str_contains($r->body(), $this->customer->email);                          // no customer contact data
        });
        $this->assertSame('OK (200)', $hook->fresh()->last_status);
    }

    public function test_slack_and_teams_get_their_own_shape_and_only_subscribed_events(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->hook('slack');
        $this->hook('teams', ['sla.breached']);                                                 // not subscribed to creation

        $this->file()->assertStatus(201);

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => isset($r->data()['text']) && str_contains($r->data()['text'], 'New ticket') && str_contains($r->data()['text'], 'Printer &lt;b&gt;on fire&lt;/b&gt;') && ! isset($r->data()['event']));
    }

    public function test_inactive_endpoints_receive_nothing_and_events_are_queued(): void
    {
        Queue::fake();
        $h = $this->hook();
        $h->update(['is_active' => false]);
        $this->file();
        Queue::assertNotPushed(DeliverWebhookJob::class);

        $h->update(['is_active' => true]);
        $this->file();
        Queue::assertPushed(DeliverWebhookJob::class, 1);
    }

    public function test_server_errors_are_retried_but_client_errors_are_not(): void
    {
        $status = 500;
        Http::fake(function () use (&$status) { return Http::response('x', $status); });
        $hook = $this->hook();
        $job = new DeliverWebhookJob($hook->id, ['event' => 'ticket.created', 'ticket' => ['number' => 'T', 'title' => 't', 'url' => 'u', 'priority' => 'low', 'department' => null, 'assignee' => null]], 'd1');

        try {
            $job->handle();
            $this->fail('A 500 should be thrown so the queue retries it');
        } catch (\RuntimeException $e) {
            $this->assertSame('HTTP 500', $e->getMessage());
        }

        $status = 410;
        $job2 = new DeliverWebhookJob($hook->id, $job->payload, 'd2');
        $job2->handle();                                                                       // no exception: permanent failure, not retried
        $this->assertSame('HTTP 410', $hook->fresh()->last_status);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertSame(4, $job->tries);
    }

    public function test_an_endpoint_is_switched_off_after_repeated_failures(): void
    {
        $hook = $this->hook();
        $hook->update(['consecutive_failures' => DeliverWebhookJob::DISABLE_AFTER - 1]);

        (new DeliverWebhookJob($hook->id, ['event' => 'ticket.created'], 'd'))->failed(new \RuntimeException('x'));

        $this->assertFalse($hook->fresh()->is_active);
        $this->assertStringContainsString('Turned off', $hook->fresh()->last_status);
    }

    public function test_delivery_rechecks_the_address_and_never_connects_to_internal_hosts(): void
    {
        Http::fake();
        $hook = $this->hook();
        // Pretend the stored URL now points inside the network (e.g. DNS changed, or the row was tampered with).
        \DB::table('webhook_endpoints')->where('id', $hook->id)->update(['url' => encrypt('https://10.1.2.3/hook')]);

        (new DeliverWebhookJob($hook->id, ['event' => 'ticket.created'], 'd'))->handle();

        Http::assertNothingSent();
        $this->assertStringContainsString('Blocked', $hook->fresh()->last_status);
    }

    public function test_a_failing_webhook_never_breaks_filing_a_ticket(): void
    {
        Http::fake(['*' => fn () => throw new \RuntimeException('network down')]);
        $this->hook();
        $this->file()->assertStatus(201);
    }

    public function test_test_button_sends_a_sample_and_reports_the_result(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $hook = $this->hook();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/webhooks/{$hook->id}/test")->assertOk()->assertJsonPath('webhook.last_status', 'OK (200)');
        Http::assertSent(fn ($r) => $r->data()['data']['test'] === true && $r->data()['ticket']['title'] === 'Test notification from DeskFlow');
    }
}
