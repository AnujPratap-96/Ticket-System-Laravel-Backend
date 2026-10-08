<?php

namespace App\Enums;

enum TicketStatus: string
{
    case OPEN = 'open';
    case IN_PROGRESS = 'in_progress';
    case PENDING_CUSTOMER = 'pending_customer';
    case RESOLVED = 'resolved';
    case CLOSED = 'closed';

    public function isTerminal(): bool
    {
        return $this === self::CLOSED;
    }

    public function pausesSla(): bool
    {
        return in_array($this, [self::PENDING_CUSTOMER, self::RESOLVED, self::CLOSED], true);
    }
}
