<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CannedResponse extends Model
{
    use BelongsToTenant;

    protected $fillable = ['title', 'body', 'department_id', 'owner_id'];
}
