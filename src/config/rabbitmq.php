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

    // Seconds the API waits for the broker to confirm a message.
    'confirm_timeout' => 3.0,

    'exchange' => 'sensor.events',
    'dead_letter_exchange' => 'sensor.events.dlx',

    // Event type => routing key.
    'routing_keys' => [
        'OCCUPIED' => 'sensor.state.occupied',
        'VACANT' => 'sensor.state.vacant',
        'HEARTBEAT' => 'sensor.telemetry.heartbeat',
        'ERROR' => 'sensor.telemetry.error',
    ],

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
