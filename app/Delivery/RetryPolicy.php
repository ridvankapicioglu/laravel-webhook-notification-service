<?php

namespace App\Delivery;

/**
 * Decides whether a failed delivery is tried again and how long to wait before doing so.
 *
 * Waits grow exponentially (10s, 30s, 90s, 270s by default) with a small random jitter, so that
 * thousands of messages failing at the same moment (e.g. provider outage during a flash sale)
 * do not all hit the provider again at exactly the same second.
 */
class RetryPolicy
{
    /**
     * @param  list<int>  $backoff  wait in seconds before the 2nd, 3rd, ... attempt
     */
    public function __construct(
        private readonly int $maxAttempts,
        private readonly array $backoff,
        private readonly float $jitter,
    ) {}

    public function shouldRetry(DeliveryResult $result, int $attemptsMade): bool
    {
        return $result->retryable && $attemptsMade < $this->maxAttempts;
    }

    public function delayFor(int $attemptsMade, ?int $retryAfter = null): int
    {
        $index = min(max($attemptsMade - 1, 0), count($this->backoff) - 1);
        $base = $this->backoff[$index];
        $delay = $base + (int) round($base * $this->jitter * mt_rand() / mt_getrandmax());

        // If the provider told us when to come back (429 + Retry-After), never come back earlier.
        return max($delay, $retryAfter ?? 0);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
