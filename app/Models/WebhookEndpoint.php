<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    public const TYPES = ['slack', 'teams', 'generic'];

    public const EVENTS = [
        'ticket.created' => 'New ticket',
        'ticket.assigned' => 'Ticket assigned',
        'ticket.status_changed' => 'Status changed',
        'ticket.customer_replied' => 'Customer replied',
        'sla.warning' => 'SLA due soon',
        'sla.breached' => 'SLA breached',
        'rating.low' => 'Low satisfaction rating',
    ];

    protected $fillable = ['name', 'type', 'url', 'secret', 'events', 'is_active', 'consecutive_failures', 'last_status', 'last_delivery_at', 'created_by'];

    protected $casts = [
        'url' => 'encrypted',
        'secret' => 'encrypted',
        'events' => 'array',
        'is_active' => 'boolean',
        'last_delivery_at' => 'datetime',
    ];

    protected $hidden = ['url', 'secret'];

    /** "https://hooks.slack.com/…/XXXX" -> "hooks.slack.com/…" : enough to recognise, not enough to use. */
    public function maskedUrl(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST) ?: 'unknown';

        return $host.'/…'.substr($this->url, -4);
    }
}
