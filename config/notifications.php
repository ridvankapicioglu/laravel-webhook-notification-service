<?php

return [

    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL', 'https://webhook.site/924656b7-59d1-49ef-bde5-124930bd4665'),
        'timeout' => (int) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
        'connect_timeout' => (int) env('NOTIFICATION_PROVIDER_CONNECT_TIMEOUT', 2),
    ],

    // At most this many provider calls per channel in any one second, shared by all workers (Redis).
    'rate_limit_per_second' => (int) env('NOTIFICATION_RATE_LIMIT', 100),

    // The limit is enforced over a slightly longer window than one second. The time between taking a
    // permit and the request reaching the provider varies by a few tens of milliseconds under load; with
    // an exact 1000 ms window the provider saw up to 105 requests in one second in a load test.
    'rate_limit_window_ms' => (int) env('NOTIFICATION_RATE_LIMIT_WINDOW_MS', 1100),

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
