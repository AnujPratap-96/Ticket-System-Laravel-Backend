<?php

namespace App\Enums;

enum UserRole: string
{
    case CUSTOMER = 'customer';
    case AGENT = 'agent';
    case LEAD = 'lead';
    case ADMIN = 'admin';
    /** The platform owner: manages workspaces (tenants), never sees any workspace's tickets or people. */
    case SUPER_ADMIN = 'super_admin';

    public function isPlatform(): bool
    {
        return $this === self::SUPER_ADMIN;
    }

    public function isStaff(): bool
    {
        return in_array($this, [self::AGENT, self::LEAD, self::ADMIN], true);
    }
}
