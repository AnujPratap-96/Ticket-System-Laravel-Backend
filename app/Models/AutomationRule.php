<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationRule extends Model
{
    public const TRIGGERS = ['ticket_created', 'customer_replied', 'idle'];

    protected $fillable = ['name', 'trigger', 'conditions', 'actions', 'idle_hours', 'is_active', 'created_by', 'runs_count'];

    protected $casts = ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'idle_hours' => 'integer'];
}
