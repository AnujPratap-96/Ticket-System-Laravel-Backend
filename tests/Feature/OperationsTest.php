<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketReceivedNotification;
use App\Services\SlaCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private Organization $org;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id, 'name' => 'Rahul Sharma']);
    }

    private function file(string $title = 'Printer down')
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => $title, 'description' => 'It does not print']);
    }

    // ---------------- acknowledgement ----------------

    public function test_the_customer_gets_an_acknowledgement_with_the_ticket_number_and_a_reply_time(): void
    {
        Notification::fake();
        $res = $this->file()->assertStatus(201);

        Notification::assertSentTo($this->customer, TicketReceivedNotification::class, function ($n) use ($res) {
            $mail = $n->toMail($this->customer);
            $html = (string) $mail->render();

            return str_contains($mail->subject, '['.$res->json('ticket.ticket_number').']')
                && str_contains($html, $res->json('ticket.ticket_number'))
                && str_contains($html, 'We aim to give you a first answer by')
                && str_contains($html, 'Infra');
        });
    }

    public function test_the_acknowledgement_goes_by_email_only(): void
    {
        $t = Ticket::create(['ticket_number' => 'TICK-A-1', 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id, 'department_id' => $this->dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);
        $this->assertSame(['mail'], (new TicketReceivedNotification($t))->via($this->customer));
    }

    // ---------------- working days ----------------

    public function test_a_sunday_to_thursday_team_has_its_clock_running_on_sunday_not_friday(): void
    {
        $calc = app(SlaCalculatorService::class);
        $friday = Carbon::parse('2027-01-08 10:00:00', 'UTC');        // a Friday
        $this->assertSame(5, $friday->dayOfWeekIso);

        $default = $this->dept;                                          // Monday to Friday
        $this->assertSame('2027-01-08 11:00', $calc->calculateTargetTimestamp($friday->copy(), 60, $default, true, collect())->format('Y-m-d H:i'));

        $gulf = Department::create(['name' => 'Gulf', 'slug' => 'gulf', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC', 'working_days' => [7, 1, 2, 3, 4]]);
        // Friday and Saturday are the weekend: 60 minutes from Friday 10:00 end on Sunday 10:00
        $this->assertSame('2027-01-10 10:00', $calc->calculateTargetTimestamp($friday->copy(), 60, $gulf, true, collect())->format('Y-m-d H:i'));
        // and the elapsed business time over that weekend is only Sunday's
        $this->assertSame(180, $calc->businessMinutesBetween($friday->copy(), Carbon::parse('2027-01-10 12:00:00', 'UTC'), $gulf, collect()));
        $this->assertSame(0, $calc->businessMinutesBetween(Carbon::parse('2027-01-08 10:00:00', 'UTC'), Carbon::parse('2027-01-09 20:00:00', 'UTC'), $gulf, collect()));   // all weekend
    }

    public function test_working_days_are_validated_and_shown(): void
    {
        $base = ['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC'];
        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['working_days' => [7, 1, 2, 3, 4]])->assertOk()->assertJsonPath('department.working_days', [7, 1, 2, 3, 4]);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['working_days' => []])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['working_days' => [8]])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['working_days' => [1, 1]])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base)->assertOk()->assertJsonPath('department.working_days', [7, 1, 2, 3, 4]);   // left alone when omitted

        $other = Department::create(['name' => 'Other', 'slug' => 'other', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->getJson('/api/v1/departments')->assertOk()->assertJsonFragment(['slug' => 'other', 'working_days' => [1, 2, 3, 4, 5]]);
    }

    // ---------------- availability / leave ----------------

    public function test_an_agent_on_leave_gets_no_new_tickets_until_they_are_back(): void
    {
        $away = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'is_available_for_routing' => true, 'last_assigned_at' => now()->subYear()]);
        $here = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'is_available_for_routing' => true, 'last_assigned_at' => now()]);

        $this->actingAs($away, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => now()->addDays(3)->toDateTimeString()])->assertOk()->assertJsonPath('user.away_until', fn ($v) => $v !== null);
        $id = $this->file()->assertStatus(201)->json('ticket.id');
        $this->assertSame($here->id, Ticket::find($id)->assigned_agent_id);                      // the one on leave would have been first in line

        // back from leave (the date passes): eligible again
        $away->forceFill(['away_until' => now()->subMinute()])->save();
        $id2 = $this->file('Second')->json('ticket.id');
        $this->assertSame($away->id, Ticket::find($id2)->assigned_agent_id);

        $this->actingAs($away, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => null])->assertOk()->assertJsonPath('user.away_until', null);
    }

    public function test_going_on_leave_can_hand_open_tickets_to_teammates(): void
    {
        $a = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'is_available_for_routing' => true]);
        $b = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id, 'is_available_for_routing' => true, 'last_assigned_at' => now()]);
        $t = Ticket::create(['ticket_number' => 'TICK-O-1', 'organization_id' => $this->org->id, 'customer_id' => $this->customer->id, 'department_id' => $this->dept->id, 'assigned_agent_id' => $a->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium']);

        $res = $this->actingAs($a, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => now()->addWeek()->toDateTimeString(), 'release_tickets' => true])->assertOk();
        $this->assertSame(1, $res->json('handed_over'));
        $this->assertSame($b->id, $t->fresh()->assigned_agent_id);
    }

    public function test_availability_rules(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->actingAs($agent, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => now()->subDay()->toDateTimeString()])->assertStatus(422);
        $this->actingAs($agent, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => now()->addYears(2)->toDateTimeString()])->assertStatus(422);
        $this->actingAs($this->customer, 'sanctum')->patchJson('/api/v1/auth/availability', ['away_until' => now()->addDay()->toDateTimeString()])->assertStatus(403);
    }

    // ---------------- time tracking ----------------

    public function test_staff_log_time_and_customers_cannot_see_or_log_it(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $other = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $id = $this->file()->json('ticket.id');

        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/tickets/{$id}/time", ['minutes' => 25, 'note' => 'Replaced toner'])->assertStatus(201);
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/tickets/{$id}/time", ['minutes' => 10, 'billable' => false])->assertStatus(201);
        $this->actingAs($agent, 'sanctum')->postJson("/api/v1/tickets/{$id}/time", ['minutes' => 0])->assertStatus(422);

        $this->actingAs($agent, 'sanctum')->getJson("/api/v1/tickets/{$id}/time")->assertOk()
            ->assertJsonPath('total_minutes', 35)->assertJsonPath('billable_minutes', 25)->assertJsonCount(2, 'entries');

        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$id}/time")->assertStatus(403);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$id}/time", ['minutes' => 5])->assertStatus(403);

        $entry = \App\Models\TicketTimeEntry::first();
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/tickets/{$id}/time/{$entry->id}")->assertStatus(403);
        $this->actingAs($agent, 'sanctum')->deleteJson("/api/v1/tickets/{$id}/time/{$entry->id}")->assertOk();
        $this->assertSame(1, \App\Models\TicketTimeEntry::count());
    }

    // ---------------- weekly summary ----------------

    public function test_weekly_summary_goes_to_each_workspaces_admins_with_its_own_numbers(): void
    {
        Notification::fake();
        $this->file('One')->assertStatus(201);
        $this->file('Two')->assertStatus(201);
        $this->admin->forceFill(['email_verified_at' => now()])->save();

        $other = \App\Models\Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $otherAdmin = \App\Tenancy\TenantContext::run($other, fn () => User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]));

        $this->artisan('reports:weekly')->assertSuccessful();

        Notification::assertSentTo($this->admin, \App\Notifications\WeeklySummaryNotification::class, function ($n) {
            $html = (string) $n->toMail($this->admin)->render();

            return $n->stats['created'] === 2 && str_contains($html, 'New tickets');
        });
        Notification::assertSentTo($otherAdmin, \App\Notifications\WeeklySummaryNotification::class, fn ($n) => $n->stats['created'] === 0);
        Notification::assertNotSentTo($this->customer, \App\Notifications\WeeklySummaryNotification::class);
    }
}
