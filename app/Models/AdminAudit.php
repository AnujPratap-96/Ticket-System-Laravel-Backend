<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per successful state-changing call made by a workspace admin. Bodies are never stored. */
class AdminAudit extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['user_id', 'method', 'route', 'targets', 'status', 'ip'];

    protected $casts = ['targets' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
