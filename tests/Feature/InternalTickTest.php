<?php

namespace Tests\Feature;

use App\Enums\SlaMetricType;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InternalTickTest extends TestCase
{
    use RefreshDatabase;

    private function overdueDeadline(): TicketSlaDeadline
    {
        $org = Organization::create(['name' => 'A', 'domain' => 'a.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'D', 'slug' => 'd', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $c = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $t = Ticket::create(['ticket_number' => 'TICK-X-1', 'organization_id' => $org->id, 'customer_id' => $c->id, 'department_id' => $dept->id,
            'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);

        return TicketSlaDeadline::create(['ticket_id' => $t->id, 'metric_type' => SlaMetricType::RESOLUTION, 'target_deadline' => now()->subMinutes(5)]);
    }

    public function test_endpoint_is_off_until_a_secret_is_configured_and_rejects_wrong_secrets(): void
    {
        config(['services.cron.secret' => '']);
        $this->postJson('/api/v1/internal/tick', [], ['X-Cron-Secret' => 'anything'])->assertStatus(503);

        config(['services.cron.secret' => 'right-secret']);
        $this->postJson('/api/v1/internal/tick')->assertStatus(401);
        $this->postJson('/api/v1/internal/tick', [], ['X-Cron-Secret' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/v1/internal/tick?secret=right-secret')->assertStatus(401);   // secrets in the URL are not accepted
    }

    public function test_tick_runs_the_scheduler_and_drains_the_queue(): void
    {
        config(['services.cron.secret' => 'right-secret', 'queue.default' => 'database']);
        $deadline = $this->overdueDeadline();
        $this->assertFalse((bool) $deadline->is_breached);

        dispatch(fn () => Cache::put('tick-test-job-ran', true, 60));       // a queued job waiting for a worker

        $this->postJson('/api/v1/internal/tick', [], ['X-Cron-Secret' => 'right-secret'])
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('queue', 'drained');

        $this->assertTrue(Cache::get('tick-test-job-ran'));                  // queue was processed without a worker service
        $this->assertTrue((bool) $deadline->fresh()->is_breached);            // and the scheduler flagged the overdue SLA
    }

    public function test_scheduled_breach_check_can_be_driven_by_the_command_the_tick_runs(): void
    {
        $deadline = $this->overdueDeadline();
        $this->artisan('sla:check-breaches')->assertSuccessful();
        $this->assertTrue((bool) $deadline->fresh()->is_breached);
    }

    public function test_overlapping_ticks_are_refused(): void
    {
        config(['services.cron.secret' => 'right-secret']);
        $lock = Cache::lock('internal-tick', 30);
        $this->assertTrue($lock->get());

        $this->postJson('/api/v1/internal/tick', [], ['X-Cron-Secret' => 'right-secret'])->assertStatus(202)->assertJsonPath('status', 'busy');
        $lock->release();
    }

    public function test_sync_queue_driver_is_reported_not_run(): void
    {
        config(['services.cron.secret' => 'right-secret', 'queue.default' => 'sync']);
        $this->postJson('/api/v1/internal/tick', [], ['X-Cron-Secret' => 'right-secret'])
            ->assertOk()->assertJsonPath('queue', 'skipped (sync queue driver)');
    }
}
