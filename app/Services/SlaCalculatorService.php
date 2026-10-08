<?php

namespace App\Services;

use App\Enums\SlaMetricType;
use App\Enums\SlaTier;
use App\Jobs\SlaBreachWarningJob;
use App\Models\BusinessHoliday;
use App\Models\Department;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketSlaDeadline;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class SlaCalculatorService
{
    private const MAX_ROLL_DAYS = 400;
    public const WARNING_LEAD_MINUTES = 15;

    /**
     * Calculate and persist SLA deadlines for a ticket.
     * Fulfilled deadlines are never reset; only open clocks are recalculated.
     */
    public function attachDeadlines(Ticket $ticket): void
    {
        $org = $ticket->organization;
        $tier = $org ? $org->sla_tier : SlaTier::STANDARD;
        $priority = $ticket->priority;

        $policy = SlaPolicy::where('tier', $tier)
            ->where('priority', $priority)
            ->first();

        if (! $policy) {
            $policy = SlaPolicy::firstOrCreate(
                ['tier' => $tier, 'priority' => $priority],
                [
                    'name' => "{$tier->value} - {$priority->value} Default Policy",
                    'first_response_time_minutes' => 120,
                    'resolution_time_minutes' => 1440,
                    'applies_business_hours_only' => true,
                ]
            );
        }

        $department = $ticket->department;
        $createdAt = Carbon::parse($ticket->created_at ?? now());
        $holidays = $this->getHolidaysLookup();

        $targets = [
            SlaMetricType::FIRST_RESPONSE->value => $policy->first_response_time_minutes,
            SlaMetricType::RESOLUTION->value => $policy->resolution_time_minutes,
        ];

        foreach ($targets as $metric => $minutes) {
            $deadline = TicketSlaDeadline::firstOrNew([
                'ticket_id' => $ticket->id,
                'metric_type' => $metric,
            ]);

            if ($deadline->exists && $deadline->is_fulfilled) {
                continue;
            }

            $target = $this->calculateTargetTimestamp(
                $createdAt,
                $minutes,
                $department,
                $policy->applies_business_hours_only,
                $holidays
            );

            $deadline->target_deadline = $target;
            $deadline->is_breached = false;
            $deadline->breached_at = null;
            $deadline->is_fulfilled = false;
            $deadline->warning_sent_at = null;
            // Keep a paused clock paused; its target is recomputed on resume.
            $deadline->save();

            $this->scheduleWarning($deadline);
        }
    }

    /**
     * Mark a deadline fulfilled (recording a breach if it was late).
     */
    public function fulfill(Ticket $ticket, SlaMetricType $metric): void
    {
        $deadline = TicketSlaDeadline::where('ticket_id', $ticket->id)
            ->where('metric_type', $metric->value)
            ->first();

        if (! $deadline || $deadline->is_fulfilled) {
            return;
        }

        $effectiveNow = $deadline->paused_at ?? now();
        $isBreached = $effectiveNow->gt($deadline->target_deadline);

        $deadline->update([
            'is_fulfilled' => true,
            'fulfilled_at' => now(),
            'paused_at' => null,
            'is_breached' => $isBreached || $deadline->is_breached,
            'breached_at' => $isBreached ? ($deadline->breached_at ?? $deadline->target_deadline) : $deadline->breached_at,
        ]);
    }

    /**
     * Stop the resolution clock (ticket waiting on customer).
     */
    public function pauseResolutionClock(Ticket $ticket): void
    {
        $deadline = $this->openResolutionDeadline($ticket);

        if ($deadline && ! $deadline->paused_at) {
            $deadline->update(['paused_at' => now()]);
        }
    }

    /**
     * Restart a paused resolution clock; the remaining business minutes at pause time
     * are re-applied from now.
     */
    public function resumeResolutionClock(Ticket $ticket): void
    {
        $deadline = $this->openResolutionDeadline($ticket);

        if (! $deadline || ! $deadline->paused_at) {
            return;
        }

        $department = $ticket->department;
        $holidays = $this->getHolidaysLookup();

        $remaining = $this->businessMinutesBetween(
            $deadline->paused_at,
            $deadline->target_deadline,
            $department,
            $holidays
        );

        $deadline->target_deadline = $this->calculateTargetTimestamp(now(), $remaining, $department, true, $holidays);
        $deadline->paused_at = null;
        $deadline->warning_sent_at = null;
        $deadline->save();

        $this->scheduleWarning($deadline);
    }

    /**
     * Reopening a resolved ticket restarts a fresh resolution clock.
     */
    public function reopenResolutionClock(Ticket $ticket): void
    {
        $deadline = TicketSlaDeadline::where('ticket_id', $ticket->id)
            ->where('metric_type', SlaMetricType::RESOLUTION->value)
            ->first();

        if (! $deadline) {
            return;
        }

        $org = $ticket->organization;
        $policy = SlaPolicy::where('tier', $org ? $org->sla_tier : SlaTier::STANDARD)
            ->where('priority', $ticket->priority)
            ->first();

        $minutes = $policy?->resolution_time_minutes ?? 1440;
        $businessOnly = $policy?->applies_business_hours_only ?? true;

        $deadline->target_deadline = $this->calculateTargetTimestamp(now(), $minutes, $ticket->department, $businessOnly);
        $deadline->is_fulfilled = false;
        $deadline->fulfilled_at = null;
        $deadline->is_breached = false;
        $deadline->breached_at = null;
        $deadline->paused_at = null;
        $deadline->warning_sent_at = null;
        $deadline->save();

        $this->scheduleWarning($deadline);
    }

    /**
     * Calculate deadline respecting department working hours, weekends, and holidays.
     * Result is returned in UTC.
     */
    public function calculateTargetTimestamp(
        Carbon $start,
        int $minutesBudget,
        Department $department,
        bool $appliesBusinessHoursOnly = true,
        ?Collection $holidays = null
    ): Carbon {
        if (! $appliesBusinessHoursOnly || $minutesBudget <= 0) {
            return $start->copy()->addMinutes(max(0, $minutesBudget))->setTimezone('UTC');
        }

        $cursor = $start->copy()->setTimezone($department->timezone ?: 'UTC');
        $holidays ??= $this->getHolidaysLookup();

        [$shiftStart, $shiftEnd] = $this->shiftBounds($department);

        $cursor = $this->normalizeToBusinessWindow($cursor, $shiftStart, $shiftEnd, $holidays);

        while ($minutesBudget > 0) {
            $shiftCloseToday = $cursor->copy()->setTimeFromTimeString($shiftEnd);
            $minutesLeftToday = max(0, (int) $cursor->diffInMinutes($shiftCloseToday, false));

            if ($minutesBudget <= $minutesLeftToday) {
                return $cursor->copy()->addMinutes($minutesBudget)->setTimezone('UTC');
            }

            $minutesBudget -= $minutesLeftToday;

            $cursor->addDay()->setTimeFromTimeString($shiftStart);
            $cursor = $this->normalizeToBusinessWindow($cursor, $shiftStart, $shiftEnd, $holidays);
        }

        return $cursor->setTimezone('UTC');
    }

    /**
     * Business minutes between two instants (used to preserve the remaining budget on pause).
     */
    public function businessMinutesBetween(
        Carbon $from,
        Carbon $to,
        Department $department,
        ?Collection $holidays = null
    ): int {
        if ($to->lte($from)) {
            return 0;
        }

        $holidays ??= $this->getHolidaysLookup();
        $tz = $department->timezone ?: 'UTC';
        [$shiftStart, $shiftEnd] = $this->shiftBounds($department);

        $cursor = $from->copy()->setTimezone($tz);
        $end = $to->copy()->setTimezone($tz);
        $total = 0;
        $days = 0;

        while ($cursor->lt($end) && $days++ < self::MAX_ROLL_DAYS) {
            if (! $cursor->isWeekend() && ! $holidays->contains($cursor->format('Y-m-d'))) {
                $dayStart = $cursor->copy()->setTimeFromTimeString($shiftStart);
                $dayEnd = $cursor->copy()->setTimeFromTimeString($shiftEnd);

                $windowStart = $cursor->gt($dayStart) ? $cursor->copy() : $dayStart;
                $windowEnd = $end->lt($dayEnd) ? $end->copy() : $dayEnd;

                if ($windowEnd->gt($windowStart)) {
                    $total += (int) $windowStart->diffInMinutes($windowEnd);
                }
            }

            $cursor = $cursor->copy()->addDay()->setTimeFromTimeString($shiftStart);
        }

        return $total;
    }

    private function shiftBounds(Department $department): array
    {
        return [
            Carbon::parse($department->business_hours_start)->format('H:i:s'),
            Carbon::parse($department->business_hours_end)->format('H:i:s'),
        ];
    }

    private function openResolutionDeadline(Ticket $ticket): ?TicketSlaDeadline
    {
        return TicketSlaDeadline::where('ticket_id', $ticket->id)
            ->where('metric_type', SlaMetricType::RESOLUTION->value)
            ->where('is_fulfilled', false)
            ->first();
    }

    private function scheduleWarning(TicketSlaDeadline $deadline): void
    {
        $fireAt = $deadline->target_deadline->copy()->subMinutes(self::WARNING_LEAD_MINUTES);

        SlaBreachWarningJob::dispatch($deadline->id, $deadline->target_deadline->toISOString())
            ->delay($fireAt->isFuture() ? $fireAt : now());
    }

    /**
     * Advance cursor to the nearest open business minute.
     */
    private function normalizeToBusinessWindow(
        Carbon $cursor,
        string $shiftStart,
        string $shiftEnd,
        Collection $holidays
    ): Carbon {
        for ($i = 0; $i < self::MAX_ROLL_DAYS; $i++) {
            if ($cursor->isWeekend() || $holidays->contains($cursor->format('Y-m-d'))) {
                $cursor->addDay()->setTimeFromTimeString($shiftStart);
                continue;
            }

            $currentShiftStart = $cursor->copy()->setTimeFromTimeString($shiftStart);
            $currentShiftEnd = $cursor->copy()->setTimeFromTimeString($shiftEnd);

            if ($cursor->lt($currentShiftStart)) {
                return $currentShiftStart;
            }

            if ($cursor->gte($currentShiftEnd)) {
                $cursor->addDay()->setTimeFromTimeString($shiftStart);
                continue;
            }

            return $cursor;
        }

        throw new RuntimeException('Unable to find an open business day; check department hours and holiday calendar.');
    }

    private function getHolidaysLookup(): Collection
    {
        return BusinessHoliday::pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'));
    }
}
