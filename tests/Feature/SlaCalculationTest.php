<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Services\SlaCalculatorService;
use Carbon\Carbon;
use Tests\TestCase;

class SlaCalculationTest extends TestCase
{
    private SlaCalculatorService $calculator;
    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new SlaCalculatorService();

        $this->dept = new Department([
            'business_hours_start' => '09:00:00',
            'business_hours_end' => '18:00:00',
            'timezone' => 'UTC',
        ]);
    }

    public function test_sla_calculation_within_same_business_day(): void
    {
        // Wednesday at 10:00 AM, 120 minutes budget -> 12:00 PM same day
        $start = Carbon::parse('2026-10-07 10:00:00', 'UTC'); // Wednesday
        $deadline = $this->calculator->calculateTargetTimestamp($start, 120, $this->dept, true);

        $this->assertEquals('2026-10-07 12:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_sla_rolls_over_shift_end_to_next_business_morning(): void
    {
        // Wednesday at 17:30 (5:30 PM), only 30 mins left before 18:00 close.
        // Budget = 90 mins. 30 mins used today; remaining 60 mins roll to Thursday morning 09:00 -> 10:00 AM.
        $start = Carbon::parse('2026-10-07 17:30:00', 'UTC');
        $deadline = $this->calculator->calculateTargetTimestamp($start, 90, $this->dept, true);

        $this->assertEquals('2026-10-08 10:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_sla_skips_weekends_entirely(): void
    {
        // Friday at 17:00 (5:00 PM), 60 mins left before close.
        // Budget = 120 mins. 60 mins used Friday.
        // Saturday & Sunday skipped.
        // Remaining 60 mins roll to Monday at 09:00 -> 10:00 AM.
        $start = Carbon::parse('2026-10-09 17:00:00', 'UTC'); // Friday
        $deadline = $this->calculator->calculateTargetTimestamp($start, 120, $this->dept, true);

        $this->assertEquals('2026-10-12 10:00:00', $deadline->format('Y-m-d H:i:s')); // Monday
    }

    public function test_ticket_submitted_outside_hours_starts_at_next_shift_opening(): void
    {
        // Ticket submitted Saturday evening at 21:00 (outside business hours).
        // Cursor rolls to Monday 09:00 AM.
        // Budget = 60 mins -> Monday 10:00 AM.
        $start = Carbon::parse('2026-10-10 21:00:00', 'UTC'); // Saturday
        $deadline = $this->calculator->calculateTargetTimestamp($start, 60, $this->dept, true);

        $this->assertEquals('2026-10-12 10:00:00', $deadline->format('Y-m-d H:i:s')); // Monday
    }
}
