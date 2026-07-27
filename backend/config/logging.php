<?php

use Monolog\Handler\StreamHandler;

return [
    'default' => env('LOG_CHANNEL', 'stderr'),
    'deprecations' => ['channel' => 'null', 'trace' => false],
    'channels' => [
        'stderr' => ['driver' => 'monolog', 'level' => env('LOG_LEVEL', 'warning'), 'handler' => StreamHandler::class, 'with' => ['stream' => 'php://stderr']],
        'stack' => ['driver' => 'stack', 'channels' => ['stderr'], 'ignore_exceptions' => false],
        'null' => ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class],
    ],
];
