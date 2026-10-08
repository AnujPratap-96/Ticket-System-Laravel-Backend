<?php

namespace App\Enums;

enum TicketChannel: string
{
    case PORTAL = 'portal';
    case EMAIL = 'email';
    case API = 'api';
}
