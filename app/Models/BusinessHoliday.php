<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessHoliday extends Model
{
    use BelongsToTenant;

    use HasFactory;

    protected $fillable = [
        'holiday_date',
        'name',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];
}
