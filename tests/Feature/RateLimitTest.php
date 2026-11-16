<?php

namespace Tests\Feature;

use App\Delivery\ChannelRateLimiter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    public function test_at_most_the_configured_number_of_calls_pass_per_channel_per_second(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $limiter = $this->limiter(100);

        $passed = collect(range(1, 150))->filter(fn () => $limiter->attempt('sms'))->count();

        $this->assertSame(100, $passed);
        $this->assertTrue($limiter->isFull('sms'));
    }

    public function test_channels_have_separate_limits(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $limiter = $this->limiter(2);

        foreach (['sms', 'email', 'push'] as $channel) {
            $this->assertTrue($limiter->attempt($channel));
            $this->assertTrue($limiter->attempt($channel));
            $this->assertFalse($limiter->attempt($channel));
        }
    }

    public function test_the_limit_resets_in_the_next_second(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $limiter = $this->limiter(1);
        $this->assertTrue($limiter->attempt('sms'));
        $this->assertFalse($limiter->attempt('sms'));

        Carbon::setTestNow('2026-11-16 12:00:01');
        $this->assertFalse($limiter->isFull('sms'));
        $this->assertTrue($limiter->attempt('sms'));
    }

    private function limiter(int $perSecond): ChannelRateLimiter
    {
        return new ChannelRateLimiter(cache()->store(), $perSecond);
    }
}
