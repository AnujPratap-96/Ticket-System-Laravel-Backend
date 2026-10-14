<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'business_hours_start',
        'business_hours_end',
        'timezone',
        'form_fields',
        'working_days',
    ];

    protected $casts = ['form_fields' => 'array', 'working_days' => 'array'];

    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    /** ISO weekdays this team works (1 = Monday ... 7 = Sunday). */
    public function workingDays(): array
    {
        return $this->working_days ?: self::DEFAULT_WORKING_DAYS;
    }

    public function agents(): HasMany
    {
        return $this->hasMany(User::class)->whereIn('role', ['agent', 'lead']);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
