<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a callback in several real processes at once and returns what each of them returned.
 *
 * Every child reports back through Redis, either its result or the exception it hit. The test
 * fails unless every process was forked, waited for, and finished without an error, so a child
 * that crashes can never make a parallel test pass by accident.
 */
trait RunsInParallel
{
    /**
     * @param  callable(int): (int|bool|string)  $work  receives the process number, 0..$processes-1
     * @param  float  $startAt  microtime() at which every child starts working, so they really overlap
     * @return list<int|bool|string>
     */
    protected function runInParallel(int $processes, callable $work, float $startAt): array
    {
        $this->skipUnlessForkIsAvailable();

        $key = 'test-parallel:'.Str::random(8);
        $children = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'Could not fork a worker process.');

            if ($pid === 0) {
                $this->runChild($i, $work, $startAt, $key);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            $this->assertSame($pid, pcntl_waitpid($pid, $status), "Could not wait for worker process {$pid}.");
        }

        // The parent's connections were shared with the children before they forked; start clean.
        DB::purge();
        Redis::purge();
        $redis = Redis::connection();

        $errors = $redis->lrange("{$key}:errors", 0, -1);
        $results = array_map(fn (string $r) => json_decode($r, true), $redis->lrange("{$key}:results", 0, -1));
        $redis->del("{$key}:errors", "{$key}:results");

        $this->assertSame([], $errors, 'A worker process failed.');
        $this->assertCount($processes, $results, 'Not every worker process reported back.');

        return $results;
    }

    private function runChild(int $number, callable $work, float $startAt, string $key): never
    {
        try {
            // Own connections, not the sockets inherited from the parent.
            DB::purge();
            Redis::purge();

            time_sleep_until($startAt);
            $result = $work($number);

            Redis::connection()->rpush("{$key}:results", json_encode($result));
        } catch (Throwable $e) {
            Redis::connection()->rpush("{$key}:errors", "worker {$number}: ".get_class($e).': '.$e->getMessage());
        } finally {
            // Leave without running PHPUnit's shutdown code in the child.
            posix_kill(getmypid(), SIGKILL);
        }

        exit(1);
    }

    private function skipUnlessForkIsAvailable(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available (it is in the docker image and in CI).');
        }
    }
}
