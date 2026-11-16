<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsInParallel;
use Tests\TestCase;

/**
 * Several real processes, each with its own MySQL connection, try to claim the same notification
 * at the same time. Uses truncation instead of a transaction so the other processes can see the row.
 */
class ConcurrentClaimTest extends TestCase
{
    use DatabaseTruncation;
    use RunsInParallel;

    public function test_only_one_of_many_parallel_workers_can_claim_a_notification(): void
    {
        $notification = Notification::factory()->create();

        // The row is really committed here; remove it so the following tests start from an empty table.
        $this->beforeApplicationDestroyed(function () {
            DB::purge();
            DB::table('notification_attempts')->delete();
            DB::table('notifications')->delete();
        });

        $startAt = microtime(true) + 0.5;

        // All workers start at the same moment, load the row and claim it.
        $claims = $this->runInParallel(10, function () use ($notification) {
            return Notification::findOrFail($notification->id)->claim();
        }, $startAt);

        $this->assertSame(1, count(array_filter($claims)), 'Exactly one worker must win the claim.');

        $notification->refresh();
        $this->assertSame(NotificationStatus::Processing, $notification->status);
        $this->assertSame(1, $notification->attempts);
    }
}
