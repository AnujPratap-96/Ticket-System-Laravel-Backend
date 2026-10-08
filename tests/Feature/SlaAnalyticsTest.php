<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $lead;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create([
            'name' => 'Support Desk',
            'slug' => 'support-desk',
        ]);

        $this->lead = User::factory()->create([
            'role' => 'lead',
            'department_id' => $dept->id,
        ]);

        $this->customer = User::factory()->create([
            'role' => 'customer',
        ]);
    }

    public function test_customer_cannot_access_analytics_endpoint(): void
    {
        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/analytics/sla-overview')
            ->assertStatus(403);
    }

    public function test_team_lead_can_access_sla_overview_and_agent_workload(): void
    {
        $overviewResponse = $this->actingAs($this->lead, 'sanctum')
            ->getJson('/api/v1/analytics/sla-overview');

        $overviewResponse->assertStatus(200)
            ->assertJsonStructure([
                'compliance_rate_percentage',
                'total_sla_tracked',
                'breached_count',
                'fulfilled_on_time_count',
                'at_risk_deadlines_count',
                'tickets_summary',
            ]);

        $workloadResponse = $this->actingAs($this->lead, 'sanctum')
            ->getJson('/api/v1/analytics/agent-workload');

        $workloadResponse->assertStatus(200)
            ->assertJsonStructure(['workload']);
    }
}
