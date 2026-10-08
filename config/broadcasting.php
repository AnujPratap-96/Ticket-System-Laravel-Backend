<?php

return [

    /*
    | Realtime updates. "reverb" pushes small "something changed" pings to open browsers over WebSockets;
    | "log"/"null" turn it off (the app then simply refreshes on its normal polling). Pings never carry
    | message text: the browser re-fetches through the normal, authorised API.
    */
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // Never let a slow or dead realtime server hold up a request for more than a moment.
            'client_options' => ['timeout' => 2, 'connect_timeout' => 1],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],

    ],

];
