<?php

namespace App\Delivery;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Str;

/**
 * At most N provider calls per channel in any sliding window (1 second by default), shared by
 * every worker.
 *
 * Each permit is a member of a Redis sorted set scored by its time in milliseconds. The Lua script
 * drops permits older than the window, counts the rest and adds a new one only if there is room.
 * Redis runs a script atomically, and the time comes from the Redis server, so all workers share
 * one clock and two of them can never take the last slot.
 *
 * The permit is taken right before the HTTP call; the limit applies to the moment calls are started.
 */
class ChannelRateLimiter
{
    private const ATTEMPT = <<<'LUA'
        local now = redis.call('TIME')
        local nowMs = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
        local window = tonumber(ARGV[1])
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', nowMs - window)
        if redis.call('ZCARD', KEYS[1]) < tonumber(ARGV[2]) then
            redis.call('ZADD', KEYS[1], nowMs, ARGV[3])
            redis.call('PEXPIRE', KEYS[1], window)
            return 1
        end
        return 0
        LUA;

    private const IS_FULL = <<<'LUA'
        local now = redis.call('TIME')
        local nowMs = tonumber(now[1]) * 1000 + math.floor(tonumber(now[2]) / 1000)
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', nowMs - tonumber(ARGV[1]))
        return redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) and 1 or 0
        LUA;

    public function __construct(
        // phpredis (the client used everywhere in this project); its wrapper takes Lua arguments as varargs.
        private readonly PhpRedisConnection $redis,
        private readonly int $perWindow,
        private readonly int $windowMs = 1000,
        private readonly string $prefix = 'rate-limit',
    ) {}

    /**
     * Cheap check, used to skip work when the window is already full.
     */
    public function isFull(string $channel): bool
    {
        return (int) $this->redis->eval(self::IS_FULL, 1, $this->key($channel), $this->windowMs, $this->perWindow) === 1;
    }

    public function attempt(string $channel): bool
    {
        return (int) $this->redis->eval(
            self::ATTEMPT, 1, $this->key($channel), $this->windowMs, $this->perWindow, (string) Str::uuid()
        ) === 1;
    }

    private function key(string $channel): string
    {
        return "{$this->prefix}:{$channel}";
    }
}
