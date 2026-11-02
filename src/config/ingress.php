<?php

return [
    // Seconds. Allows for small clock differences on gateways.
    'max_future_seconds' => (int) env('INGRESS_MAX_FUTURE_SECONDS', 300),
];
