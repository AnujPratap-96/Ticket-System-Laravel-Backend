<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private User $customer;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.inbound_email.secret' => 's3cret', 'services.inbound_email.default_department' => 'infra']);
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id, 'email' => 'rahul@acme.test']);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
    }

    private function inbound(array $payload, string $secret = 's3cret')
    {
        return $this->postJson('/api/v1/webhooks/inbound-email', $payload, ['X-Webhook-Secret' => $secret]);
    }

    private function ticket(string $status = 'in_progress'): Ticket
    {
        $t = Ticket::create([
            'ticket_number' => 'TMP', 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id, 'department_id' => $this->dept->id,
            'title' => 'T', 'description' => 'D', 'status' => $status, 'priority' => 'medium', 'assigned_agent_id' => $this->agent->id,
        ]);
        $t->update(['ticket_number' => sprintf('TICK-2026-%05d', $t->id)]);

        return $t->fresh();
    }

    public function test_requires_the_shared_secret_and_is_off_when_unconfigured(): void
    {
        $this->inbound(['From' => 'rahul@acme.test', 'Subject' => 'x', 'TextBody' => 'y'], 'wrong')->assertStatus(401);

        config(['services.inbound_email.secret' => '']);
        $this->inbound(['From' => 'rahul@acme.test', 'Subject' => 'x', 'TextBody' => 'y'], '')->assertStatus(503);
    }

    public function test_reply_is_attached_to_ticket_reopens_and_notifies_agent(): void
    {
        Notification::fake();
        $t = $this->ticket('pending_customer');

        $this->inbound([
            'From' => 'Rahul Sharma <rahul@acme.test>', 'MessageID' => 'abc-1',
            'Subject' => "Re: [{$t->ticket_number}] New reply: T",
            'TextBody' => "Here is the log you asked for.\n\nOn Tue, Oct 7, 2026 at 10:00 AM Support wrote:\n> please send logs\n> thanks",
        ])->assertStatus(202)->assertJsonPath('result', 'reply_added');

        $msg = $t->messages()->first();
        $this->assertSame('Here is the log you asked for.', $msg->body);   // quoted history removed
        $this->assertSame($this->customer->id, $msg->sender_id);
        $this->assertSame('in_progress', $t->fresh()->status->value);       // pending_customer -> in_progress
        Notification::assertSentTo($this->agent, TicketActivityNotification::class);
        $this->assertDatabaseHas('ticket_audits', ['ticket_id' => $t->id, 'event_type' => 'reply_posted']);
    }

    public function test_duplicate_delivery_is_ignored(): void
    {
        $t = $this->ticket();
        $payload = ['From' => 'rahul@acme.test', 'MessageID' => 'dup-1', 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'hello'];

        $this->inbound($payload)->assertJsonPath('result', 'reply_added');
        $this->inbound($payload)->assertJsonPath('result', 'duplicate');
        $this->assertSame(1, $t->messages()->count());
    }

    public function test_unknown_senders_and_other_peoples_tickets_are_dropped(): void
    {
        $t = $this->ticket();
        $stranger = User::factory()->create(['role' => 'customer', 'email' => 'stranger@else.test']);

        $this->inbound(['From' => 'nobody@else.test', 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'spam'])->assertJsonPath('result', 'unknown_sender');
        $this->inbound(['From' => $stranger->email, 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'hijack'])->assertJsonPath('result', 'ticket_not_found');
        $this->inbound(['From' => $this->agent->email, 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'staff are not customers'])->assertJsonPath('result', 'unknown_sender');
        $this->assertSame(0, $t->messages()->count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_closed_ticket_is_not_reopened_by_email(): void
    {
        $t = $this->ticket('closed');
        $this->inbound(['From' => $this->customer->email, 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'one more thing'])->assertJsonPath('result', 'ticket_closed');
        $this->assertSame(0, $t->messages()->count());
    }

    public function test_spf_failure_is_rejected(): void
    {
        $t = $this->ticket();
        $this->inbound([
            'From' => $this->customer->email, 'Subject' => "Re: [{$t->ticket_number}]", 'TextBody' => 'forged',
            'Headers' => [['Name' => 'Received-SPF', 'Value' => 'Fail (sender not authorised)']],
        ])->assertJsonPath('result', 'rejected_spf');
        $this->assertSame(0, $t->messages()->count());
    }

    public function test_new_email_from_known_customer_opens_a_ticket_on_the_email_channel(): void
    {
        $this->inbound(['From' => $this->customer->email, 'Subject' => 'Re: VPN is down', 'TextBody' => 'Cannot connect since morning.'])
            ->assertJsonPath('result', 'ticket_created');

        $t = Ticket::first();
        $this->assertSame('VPN is down', $t->title);
        $this->assertSame('email', $t->channel->value);
        $this->assertSame($this->agent->id, $t->assigned_agent_id);           // auto-routed
        $this->assertCount(2, $t->slaDeadlines);                              // SLA clocks started
    }
}
