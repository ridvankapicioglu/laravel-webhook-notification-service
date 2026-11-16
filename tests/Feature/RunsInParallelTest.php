<?php

namespace Tests\Feature;

use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use Tests\Concerns\RunsInParallel;
use Tests\TestCase;

/**
 * The parallel tests are only as good as their helper: make sure a broken worker cannot go unnoticed.
 */
class RunsInParallelTest extends TestCase
{
    use RunsInParallel;

    public function test_it_returns_one_result_per_worker(): void
    {
        $results = $this->runInParallel(4, fn (int $number) => $number * 10, microtime(true) + 0.2);

        sort($results);
        $this->assertSame([0, 10, 20, 30], $results);
    }

    public function test_a_failing_worker_fails_the_test(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->expectExceptionMessage('A worker process failed.');

        $this->runInParallel(3, function (int $number) {
            if ($number === 1) {
                throw new RuntimeException('boom');
            }

            return true;
        }, microtime(true) + 0.2);
    }
}
