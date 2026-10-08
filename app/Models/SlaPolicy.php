<?php

namespace App\Models;

use App\Enums\SlaTier;
use App\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SlaPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tier',
        'priority',
        'first_response_time_minutes',
        'resolution_time_minutes',
        'applies_business_hours_only',
    ];

    protected $casts = [
        'tier' => SlaTier::class,
        'priority' => TicketPriority::class,
        'first_response_time_minutes' => 'integer',
        'resolution_time_minutes' => 'integer',
        'applies_business_hours_only' => 'boolean',
    ];
}
