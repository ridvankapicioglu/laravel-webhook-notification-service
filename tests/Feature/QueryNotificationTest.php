<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueryNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_shows_a_notification_with_its_delivery_attempts(): void
    {
        $notification = Notification::factory()->status(NotificationStatus::Sent)->create(['attempts' => 1]);
        $notification->deliveryAttempts()->create([
            'attempt' => 1, 'successful' => true, 'status_code' => 202, 'duration_ms' => 120,
        ]);

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.delivery_attempts.0.status_code', 202);
    }

    public function test_unknown_or_malformed_id_returns_404(): void
    {
        $this->getJson('/api/notifications/0198c3a4-0000-7000-8000-000000000000')->assertNotFound();
        $this->getJson('/api/notifications/not-a-uuid')->assertNotFound();
    }

    public function test_it_filters_by_status_and_channel(): void
    {
        Notification::factory()->count(2)->create();
        Notification::factory()->status(NotificationStatus::Sent)->create();
        Notification::factory()->email()->status(NotificationStatus::Sent)->create();

        $this->getJson('/api/notifications?status=sent')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/notifications?status=sent&channel=email')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notifications?status=unknown')->assertStatus(422);
    }

    public function test_it_filters_by_date_range(): void
    {
        Notification::factory()->create(['created_at' => '2026-01-01 10:00:00']);
        Notification::factory()->create(['created_at' => '2026-01-05 10:00:00']);
        Notification::factory()->create(['created_at' => '2026-01-10 10:00:00']);

        $this->getJson('/api/notifications?from=2026-01-02&to=2026-01-09')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/notifications?from=2026-01-09&to=2026-01-02')->assertStatus(422);
    }

    public function test_it_paginates_newest_first(): void
    {
        Notification::factory()->count(30)->create();
        $newest = Notification::factory()->create(['created_at' => now()->addMinute()]);

        $this->getJson('/api/notifications?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.last_page', 4);

        $this->getJson('/api/notifications?per_page=500')->assertStatus(422);
    }

    public function test_a_pending_notification_can_be_cancelled(): void
    {
        $notification = Notification::factory()->create();

        $this->postJson("/api/notifications/{$notification->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($notification->fresh()->cancelled_at);
    }

    public function test_only_pending_notifications_can_be_cancelled(): void
    {
        foreach ([NotificationStatus::Processing, NotificationStatus::Sent, NotificationStatus::Failed] as $status) {
            $notification = Notification::factory()->status($status)->create();

            $this->postJson("/api/notifications/{$notification->id}/cancel")->assertStatus(409);
            $this->assertSame($status, $notification->fresh()->status);
        }
    }

    public function test_a_failed_notification_can_be_retried_manually(): void
    {
        $notification = Notification::factory()->status(NotificationStatus::Failed)->create([
            'attempts' => 5, 'failed_at' => now(), 'last_error' => 'provider_error: http 500',
        ]);

        $this->postJson("/api/notifications/{$notification->id}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempts', 0);

        Queue::assertPushed(SendNotification::class, fn ($job) => $job->notificationId === $notification->id);
    }

    public function test_only_failed_notifications_can_be_retried(): void
    {
        $notification = Notification::factory()->status(NotificationStatus::Sent)->create();

        $this->postJson("/api/notifications/{$notification->id}/retry")->assertStatus(409);
        Queue::assertNothingPushed();
    }
}
