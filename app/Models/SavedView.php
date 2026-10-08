<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    protected $fillable = ['user_id', 'name', 'filters'];

    protected $casts = ['filters' => 'array'];
}
