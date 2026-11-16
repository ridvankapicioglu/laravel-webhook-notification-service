<?php

return [

    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL', 'https://webhook.site/924656b7-59d1-49ef-bde5-124930bd4665'),
        'timeout' => (int) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
        'connect_timeout' => (int) env('NOTIFICATION_PROVIDER_CONNECT_TIMEOUT', 2),
    ],

    // Messages per second, per channel. Shared by all workers through the redis cache store.
    'rate_limit_per_second' => (int) env('NOTIFICATION_RATE_LIMIT', 100),

    'batch_limit' => 1000,

    'content_limits' => [
        'sms' => 160,
        'email' => 10000,
        'push' => 1000,
    ],

    // Total provider attempts (first try included) and the wait before each retry, in seconds.
    'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 5),
    'backoff' => [10, 30, 90, 270],
    'backoff_jitter' => 0.2,

    // A notification stuck in "processing" longer than this is considered abandoned by its worker.
    'stuck_after_minutes' => 5,
];
