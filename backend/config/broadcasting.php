<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Driver de broadcast utilisé par défaut (cf. Sprint 18.3 — Reverb).
    | En tests on bascule sur 'null' pour ne pas déclencher de connexions externes.
    |
    */
    // Default 'log' (safe, ne nécessite pas de config Reverb au boot).
    // En prod : set BROADCAST_CONNECTION=reverb + REVERB_APP_* dans .env.
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key'    => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST', 'localhost'),
                'port'   => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle options (cf. https://docs.guzzlephp.org/en/stable/request-options.html)
            ],
        ],

        'pusher' => [
            'driver'  => 'pusher',
            'key'     => env('PUSHER_APP_KEY'),
            'secret'  => env('PUSHER_APP_SECRET'),
            'app_id'  => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host'    => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
                'port'    => env('PUSHER_PORT', 443),
                'scheme'  => env('PUSHER_SCHEME', 'https'),
                'useTLS'  => env('PUSHER_SCHEME', 'https') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
