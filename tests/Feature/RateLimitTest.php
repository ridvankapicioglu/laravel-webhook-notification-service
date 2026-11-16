<?php

namespace Tests\Feature;

use App\Delivery\ChannelRateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Concerns\RunsInParallel;
use Tests\TestCase;

/**
 * Runs against real Redis: the limiter is a Lua script, an in-memory fake would prove nothing.
 */
class RateLimitTest extends TestCase
{
    use RunsInParallel;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh key per test, so tests never see each other's permits.
        $this->prefix = 'test-rate-limit:'.Str::random(8);
    }

    public function test_at_most_the_configured_number_of_calls_pass_per_channel(): void
    {
        $limiter = $this->limiter(100);

        $passed = collect(range(1, 150))->filter(fn () => $limiter->attempt('sms'))->count();

        $this->assertSame(100, $passed);
        $this->assertTrue($limiter->isFull('sms'));
    }

    public function test_channels_have_separate_limits(): void
    {
        $limiter = $this->limiter(2);

        foreach (['sms', 'email', 'push'] as $channel) {
            $this->assertTrue($limiter->attempt($channel));
            $this->assertTrue($limiter->attempt($channel));
            $this->assertFalse($limiter->attempt($channel));
        }
    }

    public function test_the_window_slides_instead_of_resetting_at_a_second_boundary(): void
    {
        $limiter = $this->limiter(2, windowMs: 400);

        $this->assertTrue($limiter->attempt('sms'));
        usleep(250_000);
        $this->assertTrue($limiter->attempt('sms'));
        $this->assertFalse($limiter->attempt('sms'));

        // 450 ms after the first permit only that one has left the window, so exactly one slot is free.
        usleep(200_000);
        $this->assertTrue($limiter->attempt('sms'));
        $this->assertFalse($limiter->attempt('sms'));

        // Once the whole window has passed, it is empty again.
        usleep(450_000);
        $this->assertFalse($limiter->isFull('sms'));
    }

    public function test_parallel_workers_never_get_more_than_the_limit(): void
    {
        // 8 processes ask for 50 permits each at the same moment; a long window keeps timing out of the picture.
        $granted = $this->runInParallel(8, function () {
            $limiter = $this->limiter(100, windowMs: 10_000);

            return collect(range(1, 50))->filter(fn () => $limiter->attempt('sms'))->count();
        }, startAt: microtime(true) + 0.5);

        $this->assertSame(100, array_sum($granted));
    }

    private function limiter(int $perWindow, int $windowMs = 1000): ChannelRateLimiter
    {
        return new ChannelRateLimiter(Redis::connection(), $perWindow, $windowMs, $this->prefix);
    }
}
