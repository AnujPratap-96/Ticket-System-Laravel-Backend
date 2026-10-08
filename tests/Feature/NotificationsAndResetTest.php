<?php

namespace Tests\Feature;

use App\Enums\SlaMetricType;
use App\Jobs\SlaBreachWarningJob;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Notifications\TicketActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsAndResetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private User $customer;
    private User $agent;
    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id, 'password' => 'oldpassword1']);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
    }

    private function ticket(array $attrs = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'TICK-N-'.uniqid(), 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id,
            'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium',
        ], $attrs));
    }

    private function codeFrom(string $purpose): string
    {
        $code = null;
        Notification::assertSentOnDemand(OtpNotification::class, function ($n) use (&$code, $purpose) {
            if ($n->purpose === $purpose) {
                $code = $n->code;
            }
            return true;
        });
        return $code;
    }

    public function test_password_reset_flow_ends_all_sessions(): void
    {
        Notification::fake();
        $oldToken = $this->customer->createToken('t')->plainTextToken;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $this->customer->email])->assertStatus(202);
        $code = $this->codeFrom('password_reset');

        $this->postJson('/api/v1/auth/reset-password', ['email' => $this->customer->email, 'code' => '999999', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $this->customer->email, 'code' => $code, 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $this->customer->email, 'password' => 'oldpassword1'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => $this->customer->email, 'password' => 'newpassword1'])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->customer->id, 'name' => 't']);
        $this->assertNotEmpty($oldToken);
    }

    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@nowhere.test'])->assertStatus(202);
        Notification::assertNothingSent();
    }

    public function test_agent_is_notified_when_customer_replies_and_when_assigned(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['assigned_agent_id' => $this->agent->id]);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$ticket->id}/messages", ['body' => 'any update?'])->assertStatus(201);
        Notification::assertSentTo($this->agent, TicketActivityNotification::class, fn ($n) => $n->kind === 'customer_replied');
        Notification::assertNotSentTo($this->lead, TicketActivityNotification::class);

        // Unassigned: the department lead hears about it instead.
        $unassigned = $this->ticket();
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$unassigned->id}/messages", ['body' => 'hello?'])->assertStatus(201);
        Notification::assertSentTo($this->lead, TicketActivityNotification::class);

        // Lead assigns to the agent
        $this->actingAs($this->lead, 'sanctum')->patchJson("/api/v1/tickets/{$unassigned->id}/assign", ['agent_id' => $this->agent->id])->assertOk();
        Notification::assertSentTo($this->agent, TicketActivityNotification::class, fn ($n) => $n->kind === 'assigned');
    }

    public function test_self_assignment_does_not_notify_yourself(): void
    {
        Notification::fake();
        $ticket = $this->ticket();
        $this->actingAs($this->agent, 'sanctum')->patchJson("/api/v1/tickets/{$ticket->id}/assign", ['agent_id' => $this->agent->id])->assertOk();
        Notification::assertNothingSentTo($this->agent);
    }

    public function test_sla_warning_job_notifies_staff_once(): void
    {
        Notification::fake();
        $ticket = $this->ticket(['assigned_agent_id' => $this->agent->id]);
        $deadline = TicketSlaDeadline::create(['ticket_id' => $ticket->id, 'metric_type' => SlaMetricType::RESOLUTION, 'target_deadline' => now()->addMinutes(10)]);

        $job = new SlaBreachWarningJob($deadline->id, $deadline->target_deadline->toISOString());
        $job->handle(app(\App\Services\AuditLoggerService::class), app(\App\Services\TicketNotifier::class));
        $job->handle(app(\App\Services\AuditLoggerService::class), app(\App\Services\TicketNotifier::class)); // second run is a no-op

        Notification::assertSentToTimes($this->agent, TicketActivityNotification::class, 1);
    }

    public function test_bell_lists_and_marks_notifications_read(): void
    {
        $ticket = $this->ticket(['assigned_agent_id' => $this->agent->id]);
        $this->agent->notify(TicketActivityNotification::assigned($ticket));
        $this->customer->notify(TicketActivityNotification::assigned($ticket));

        $res = $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(1, $res->json('unread_count'));
        $this->assertSame('assigned', $res->json('notifications.0.kind'));

        // Cannot mark someone else's notification
        $theirs = $this->customer->notifications()->first()->id;
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/notifications/{$theirs}/read")->assertStatus(404);

        $mine = $res->json('notifications.0.id');
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/notifications/{$mine}/read")->assertOk();
        $this->assertSame(0, $this->actingAs($this->agent, 'sanctum')->getJson('/api/v1/notifications')->json('unread_count'));
    }
}
