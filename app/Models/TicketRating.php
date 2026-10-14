<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRating extends Model
{
    use BelongsToTenant;

    protected $fillable = ['ticket_id', 'customer_id', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
