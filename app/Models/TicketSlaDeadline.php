<?php

namespace App\Models;

use App\Enums\SlaMetricType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaDeadline extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'metric_type',
        'target_deadline',
        'breached_at',
        'is_breached',
        'is_fulfilled',
        'fulfilled_at',
        'paused_at',
        'warning_sent_at',
    ];

    protected $casts = [
        'metric_type' => SlaMetricType::class,
        'target_deadline' => 'datetime',
        'breached_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'paused_at' => 'datetime',
        'warning_sent_at' => 'datetime',
        'is_breached' => 'boolean',
        'is_fulfilled' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
