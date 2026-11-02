<?php

return [
    // Shared secret that the gateway sends in the X-Gateway-Token header.
    'gateway_token' => env('GATEWAY_TOKEN'),

    // Seconds. Allows for small clock differences on gateways.
    'max_future_seconds' => (int) env('INGRESS_MAX_FUTURE_SECONDS', 300),

    // Where gateway:emulate sends packets. "web" is the Nginx service in Compose.
    'emulator_url' => 'http://web/api/v1/telemetry/ingress',
];
