<?php

namespace App\Delivery;

use Illuminate\Contracts\Cache\Repository;

/**
 * At most N provider calls per channel in any calendar second, shared by every worker.
 *
 * One counter per channel per second; INCR is atomic in Redis, so two workers can never
 * both take the last slot. The slot must be taken right before the HTTP call: taking it
 * earlier (e.g. at the start of the job) lets the call drift into the next second.
 */
class ChannelRateLimiter
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $perSecond,
    ) {}

    /**
     * Cheap read-only check, used to skip work when the current second is already full.
     */
    public function isFull(string $channel): bool
    {
        return (int) $this->cache->get($this->key($channel), 0) >= $this->perSecond;
    }

    public function attempt(string $channel): bool
    {
        while (true) {
            $second = now()->timestamp;
            $key = $this->key($channel, $second);

            $this->cache->add($key, 0, 2);

            if ($this->cache->increment($key) > $this->perSecond) {
                return false;
            }

            // The Redis round trip can end in the next second. Then the slot we got belongs to the
            // previous second and the call would land in a second that has not counted it, so we
            // take a slot in the new second instead. The unused one only lowers capacity.
            if (now()->timestamp === $second) {
                return true;
            }
        }
    }

    private function key(string $channel, ?int $second = null): string
    {
        return "throttle:{$channel}:".($second ?? now()->timestamp);
    }
}
