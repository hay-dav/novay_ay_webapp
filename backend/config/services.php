<?php

return [
    'payment' => [
        'provider' => env('PAYMENT_PROVIDER', 'mock'),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    ],

    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
        'app_key' => env('PUSHER_APP_KEY'),
        'app_secret' => env('PUSHER_APP_SECRET'),
        'options' => [
            'host' => env('PUSHER_HOST', '127.0.0.1'),
            'port' => env('PUSHER_PORT', 6001),
            'scheme' => env('PUSHER_SCHEME', 'http'),
            'encrypted' => false,
            'useTLS' => env('PUSHER_SCHEME') === 'https',
        ],
    ],

    'livekit' => [
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'public_url' => env('LIVEKIT_PUBLIC_URL', 'ws://localhost:7880'),
    ],
];
