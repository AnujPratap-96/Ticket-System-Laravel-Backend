<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Enums\SlaTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'sla_tier',
        'is_active',
        'monthly_ticket_limit',
        'max_customers',
        'max_open_per_customer',
    ];

    protected $casts = [
        'sla_tier' => SlaTier::class,
        'is_active' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
