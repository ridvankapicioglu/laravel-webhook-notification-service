<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_notifications_stuck_in_processing_are_queued_again(): void
    {
        $stuck = Notification::factory()->status(NotificationStatus::Processing)->create([
            'attempts' => 1, 'updated_at' => now()->subMinutes(10),
        ]);
        $active = Notification::factory()->status(NotificationStatus::Processing)->create([
            'attempts' => 1, 'updated_at' => now()->subSeconds(10),
        ]);

        $this->artisan('notifications:recover')->assertSuccessful();

        $this->assertSame(NotificationStatus::Pending, $stuck->fresh()->status);
        $this->assertSame('worker_lost', $stuck->fresh()->last_error);
        $this->assertSame(NotificationStatus::Processing, $active->fresh()->status);

        Queue::assertPushed(SendNotification::class, fn ($job) => $job->notificationId === $stuck->id);
        Queue::assertNotPushed(SendNotification::class, fn ($job) => $job->notificationId === $active->id);
    }

    public function test_stuck_notification_without_attempts_left_is_marked_failed(): void
    {
        $stuck = Notification::factory()->status(NotificationStatus::Processing)->create([
            'attempts' => 5, 'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('notifications:recover')->assertSuccessful();

        $this->assertSame(NotificationStatus::Failed, $stuck->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_notifications_that_never_reached_the_queue_are_dispatched(): void
    {
        // Saved, but pushing the job to Redis failed: dispatched_at stayed NULL.
        $neverQueued = Notification::factory()->create(['created_at' => now()->subMinutes(2), 'dispatched_at' => null]);
        $justCreated = Notification::factory()->create(['dispatched_at' => null]);

        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertPushed(SendNotification::class, 1);
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->notificationId === $neverQueued->id);
        $this->assertNotNull($neverQueued->fresh()->dispatched_at);
        $this->assertNull($justCreated->fresh()->dispatched_at);
    }

    public function test_a_lost_job_is_recovered_even_while_the_queue_is_busy(): void
    {
        $lost = Notification::factory()->create([
            'available_at' => now()->subMinutes(20),
            'dispatched_at' => now()->subMinutes(20),
        ]);

        // Constant traffic and a job scheduled far in the future keep the queue non-empty.
        Queue::pushOn('normal', new SendNotification('other-busy', 'sms'));
        Queue::later(now()->addDay(), new SendNotification('other-scheduled', 'sms'), '', 'normal');

        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertPushed(SendNotification::class, fn ($job) => $job->notificationId === $lost->id);
    }

    public function test_recently_queued_or_scheduled_notifications_are_left_alone(): void
    {
        Notification::factory()->create(['available_at' => now()->subMinutes(20), 'dispatched_at' => now()->subMinute()]);
        Notification::factory()->create(['available_at' => now()->addHour(), 'dispatched_at' => now()->subHour()]);

        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_a_lost_notification_is_dispatched_again_at_most_once_per_window(): void
    {
        Notification::factory()->create(['available_at' => now()->subMinutes(20), 'dispatched_at' => now()->subMinutes(20)]);

        $this->artisan('notifications:recover')->assertSuccessful();
        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertPushed(SendNotification::class, 1);
    }
}
