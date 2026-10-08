<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\TicketActivityNotification;
use App\Notifications\TicketReplyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.webpush.public_key' => 'BPUBLIC', 'services.webpush.private_key' => 'PRIVATE']);
        $this->user = User::factory()->create(['role' => 'customer']);
    }

    private function sub(string $endpoint = self::FCM): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKEY', 'auth' => 'AUTHKEY']];
    }

    public function test_the_public_key_is_exposed_only_when_push_is_configured(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/push/key')->assertOk()->assertJsonPath('public_key', 'BPUBLIC');
        config(['services.webpush.private_key' => null]);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/push/key')->assertOk()->assertJsonPath('public_key', null);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub())->assertStatus(503);
    }

    public function test_subscribing_is_idempotent_and_can_be_undone(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub())->assertStatus(201);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub())->assertStatus(201);
        $this->assertSame(1, PushSubscription::count());

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/unsubscribe', ['endpoint' => self::FCM])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_only_real_push_services_are_accepted_because_the_server_will_call_them(): void
    {
        foreach (['https://evil.example/collect', 'http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://169.254.169.254/x', 'https://fcm.googleapis.com.evil.example/x', 'https://user:pw@fcm.googleapis.com/x', 'https://notgoogleapis.com/x'] as $bad) {
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub($bad))->assertStatus(422);
        }
        foreach (['https://updates.push.services.mozilla.com/wpush/v2/xyz', 'https://web.push.apple.com/abc', 'https://wns2-par02p.notify.windows.com/w/?token=1'] as $good) {
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub($good))->assertStatus(201);
        }
        $this->assertSame(3, PushSubscription::count());
    }

    public function test_a_device_that_changes_hands_belongs_to_the_new_user_only(): void
    {
        $other = User::factory()->create(['role' => 'agent']);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub())->assertStatus(201);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub())->assertStatus(201);

        $this->assertSame([$other->id], PushSubscription::pluck('user_id')->all());
    }

    public function test_at_most_ten_devices_per_account(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub("https://fcm.googleapis.com/fcm/send/dev{$i}"))->assertStatus(201);
        }
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub('https://fcm.googleapis.com/fcm/send/dev11'))->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/push/subscribe', $this->sub('https://fcm.googleapis.com/fcm/send/dev3'))->assertStatus(201);   // re-subscribing is fine
    }

    public function test_notifications_use_push_only_for_people_who_subscribed_and_it_is_configured(): void
    {
        $org = Organization::create(['name' => 'A', 'domain' => 'a.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'I', 'slug' => 'i', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $t = Ticket::create(['ticket_number' => 'TICK-P-1', 'organization_id' => $org->id, 'customer_id' => $this->user->id, 'department_id' => $dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);
        $n = TicketActivityNotification::assigned($t);

        $this->assertNotContains(WebPushChannel::class, $n->via($this->user));
        PushSubscription::create(['user_id' => $this->user->id, 'endpoint_hash' => hash('sha256', self::FCM), 'endpoint' => self::FCM, 'p256dh' => 'k', 'auth' => 'a']);
        $this->assertContains(WebPushChannel::class, $n->via($this->user));

        config(['services.webpush.private_key' => null]);
        $this->assertNotContains(WebPushChannel::class, $n->via($this->user));
    }

    public function test_push_text_is_private_for_customers_and_useful_for_staff(): void
    {
        $org = Organization::create(['name' => 'A', 'domain' => 'a.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'I', 'slug' => 'i', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
        $t = Ticket::create(['ticket_number' => 'TICK-P-2', 'organization_id' => $org->id, 'customer_id' => $this->user->id, 'department_id' => $dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);

        $forStaff = TicketActivityNotification::customerReplied($t, 'My card number is private <b>info</b>')->toWebPush($agent);
        $this->assertSame('/staff/tickets/'.$t->id, $forStaff['url']);
        $this->assertStringContainsString('private', $forStaff['body']);
        $this->assertStringNotContainsString('<b>', $forStaff['body']);

        $msg = new \App\Models\TicketMessage(['body' => 'Your refund of $500 was approved']);
        $msg->setRelation('sender', $agent);
        $forCustomer = (new TicketReplyNotification($t, $msg))->toWebPush($this->user);
        $this->assertSame('/portal/tickets/'.$t->id, $forCustomer['url']);
        $this->assertStringNotContainsString('refund', json_encode($forCustomer));
    }

    public function test_a_stored_subscription_to_a_disallowed_address_is_dropped_never_called(): void
    {
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
        config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);
        PushSubscription::create(['user_id' => $this->user->id, 'endpoint_hash' => 'x', 'endpoint' => 'https://10.0.0.5/steal', 'p256dh' => 'k', 'auth' => 'a']);

        (new WebPushChannel)->send($this->user, new class extends \Illuminate\Notifications\Notification {
            public function toWebPush($n): array { return ['title' => 't', 'body' => 'b', 'url' => '/', 'tag' => 't']; }
        });

        $this->assertSame(0, PushSubscription::count());
    }
}
