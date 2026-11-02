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

    'exchange' => 'sensor.events',
    'dead_letter_exchange' => 'sensor.events.dlx',

    // RabbitMQ counts redeliveries, not the first delivery.
    // A limit of 4 means 5 deliveries in total.
    'delivery_limit' => 4,

    'queues' => [
        'state' => [
            'name' => 'real_time_state_processing',
            'binding' => 'sensor.state.*',
        ],
        'telemetry' => [
            'name' => 'telemetry_analytics',
            'binding' => 'sensor.#',
        ],
        'dead_letter' => [
            'name' => 'dead_letter_queue',
        ],
    ],
];
