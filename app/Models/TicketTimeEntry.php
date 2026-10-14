<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Staff-only record of time spent on a ticket. Customers never see these. */
class TicketTimeEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['ticket_id', 'user_id', 'minutes', 'note', 'billable'];

    protected $casts = ['billable' => 'boolean', 'minutes' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
