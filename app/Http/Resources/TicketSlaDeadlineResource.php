<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketSlaDeadlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $now = now();
        $isFulfilled = (bool) $this->is_fulfilled;
        $isPaused = ! $isFulfilled && $this->paused_at !== null;
        $isBreached = (bool) $this->is_breached || (! $isFulfilled && ! $isPaused && $now->gt($this->target_deadline));

        $minutesRemaining = ! $isFulfilled
            ? max(0, (int) $now->diffInMinutes($this->target_deadline, false))
            : 0;

        return [
            'id' => $this->id,
            'metric_type' => is_string($this->metric_type) ? $this->metric_type : $this->metric_type->value,
            'target_deadline' => $this->target_deadline?->toISOString(),
            'is_breached' => $isBreached,
            'is_fulfilled' => $isFulfilled,
            'is_paused' => $isPaused,
            'fulfilled_at' => $this->fulfilled_at?->toISOString(),
            'minutes_remaining' => $minutesRemaining,
        ];
    }
}
