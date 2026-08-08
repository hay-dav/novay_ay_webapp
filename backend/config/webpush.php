<?php

return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
    'ttl' => env('WEB_PUSH_TTL', 86400),
];
