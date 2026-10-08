<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAudit;
use App\Models\User;

class AuditLoggerService
{
    public function log(
        Ticket $ticket,
        string $eventType,
        ?string $fieldName = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?User $actor = null,
        ?string $ipAddress = null
    ): TicketAudit {
        return TicketAudit::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'field_name' => $fieldName,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }
}
