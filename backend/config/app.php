<?php

return [
    'name' => env('APP_NAME', 'Новая Я'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Saratov'),
    'locale' => env('APP_LOCALE', 'ru'),
    'fallback_locale' => 'ru',
    'faker_locale' => 'ru_RU',
    'key' => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    'cipher' => 'AES-256-CBC',
    'maintenance' => ['driver' => 'file', 'store' => 'database'],
];
