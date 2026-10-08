<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'business_hours_start',
        'business_hours_end',
        'timezone',
        'form_fields',
    ];

    protected $casts = ['form_fields' => 'array'];

    public function agents(): HasMany
    {
        return $this->hasMany(User::class)->whereIn('role', ['agent', 'lead']);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
