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

    public function test_due_pending_notifications_are_requeued_when_their_queue_is_empty(): void
    {
        $lost = Notification::factory()->create(['available_at' => now()->subMinutes(5)]);
        $fresh = Notification::factory()->create(['available_at' => now()]);
        $scheduled = Notification::factory()->create(['available_at' => now()->addHour()]);

        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertPushed(SendNotification::class, 1);
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->notificationId === $lost->id);
    }

    public function test_pending_notifications_are_left_alone_while_their_queue_has_jobs(): void
    {
        Notification::factory()->create(['available_at' => now()->subMinutes(5)]);
        Queue::pushOn('normal', new SendNotification('other', 'sms'));

        $this->artisan('notifications:recover')->assertSuccessful();

        Queue::assertPushed(SendNotification::class, 1);
    }
}
