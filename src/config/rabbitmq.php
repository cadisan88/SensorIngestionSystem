<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER'),
    'password' => env('RABBITMQ_PASSWORD'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Seconds. Short, so a dead broker fails fast.
    'connection_timeout' => 3.0,
    'read_write_timeout' => 3.0,
];
