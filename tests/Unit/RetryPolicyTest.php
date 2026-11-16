<?php

namespace Tests\Unit;

use App\Delivery\DeliveryResult;
use App\Delivery\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    private RetryPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RetryPolicy(maxAttempts: 5, backoff: [10, 30, 90, 270], jitter: 0.2);
    }

    public function test_delay_grows_with_each_attempt_and_stays_within_jitter(): void
    {
        foreach ([1 => 10, 2 => 30, 3 => 90, 4 => 270, 9 => 270] as $attempt => $base) {
            for ($i = 0; $i < 50; $i++) {
                $delay = $this->policy->delayFor($attempt);

                $this->assertGreaterThanOrEqual($base, $delay);
                $this->assertLessThanOrEqual((int) round($base * 1.2), $delay);
            }
        }
    }

    public function test_retry_after_from_provider_is_never_undercut(): void
    {
        $this->assertSame(300, $this->policy->delayFor(1, retryAfter: 300));
        $this->assertGreaterThanOrEqual(10, $this->policy->delayFor(1, retryAfter: 1));
    }

    public function test_only_retryable_results_within_budget_are_retried(): void
    {
        $temporary = DeliveryResult::retryable(503, 'provider_error', 10);
        $rejected = DeliveryResult::rejected(400, 'bad request', 10);

        $this->assertTrue($this->policy->shouldRetry($temporary, 1));
        $this->assertTrue($this->policy->shouldRetry($temporary, 4));
        $this->assertFalse($this->policy->shouldRetry($temporary, 5));
        $this->assertFalse($this->policy->shouldRetry($rejected, 1));
    }
}
