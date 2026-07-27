<?php

return [
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'initial_password' => env('ADMIN_INITIAL_PASSWORD'),
    ],
    'curator' => [
        'email' => env('CURATOR_EMAIL'),
        'initial_password' => env('CURATOR_INITIAL_PASSWORD'),
    ],
];
