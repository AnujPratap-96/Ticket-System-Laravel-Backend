<?php

namespace App\Enums;

enum UserRole: string
{
    case CUSTOMER = 'customer';
    case AGENT = 'agent';
    case LEAD = 'lead';
    case ADMIN = 'admin';

    public function isStaff(): bool
    {
        return in_array($this, [self::AGENT, self::LEAD, self::ADMIN], true);
    }
}
