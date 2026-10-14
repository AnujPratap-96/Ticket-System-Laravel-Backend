<?php

/*
| Usage limits per support plan. null = unlimited. Each organization can override the three plan limits
| (Manage > Organizations), and the "global" block caps things for everyone.
*/
return [
    'tiers' => [
        'standard' => ['monthly_tickets' => 100, 'customers' => 25, 'open_per_customer' => 10],
        'silver' => ['monthly_tickets' => 500, 'customers' => 100, 'open_per_customer' => 20],
        'gold' => ['monthly_tickets' => 2000, 'customers' => 500, 'open_per_customer' => 50],
        'platinum' => ['monthly_tickets' => null, 'customers' => null, 'open_per_customer' => 100],
    ],

    'global' => [
        'sessions_per_user' => 10,        // signed-in devices; the oldest is signed out when exceeded
        'webhooks' => 10,
        'automation_rules' => 25,
        'canned_responses_per_user' => 50,
        'saved_views_per_user' => 25,
    ],

    // Abuse protection on writing (per signed-in person)
    'rate' => [
        'tickets_per_minute' => 5,
        'tickets_per_hour' => 30,
        'messages_per_minute' => 20,
    ],
];
