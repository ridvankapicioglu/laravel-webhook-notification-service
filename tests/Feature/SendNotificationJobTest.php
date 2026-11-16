<?php

namespace Tests\Feature;

use App\Delivery\ChannelRateLimiter;
use App\Delivery\NotificationProvider;
use App\Delivery\RetryPolicy;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.provider.url' => 'https://webhook.site/test-uuid']);
    }

    public function test_successful_delivery_marks_the_notification_as_sent(): void
    {
        Http::fake(['webhook.site/*' => Http::response([
            'messageId' => 'provider-msg-1', 'status' => 'accepted', 'timestamp' => now()->toIso8601String(),
        ], 202)]);

        $notification = Notification::factory()->create(['recipient' => '+905551234567', 'content' => 'Hello']);

        $this->runJob($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame('provider-msg-1', $notification->provider_message_id);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);

        $this->assertDatabaseHas('notification_attempts', [
            'notification_id' => $notification->id, 'attempt' => 1, 'successful' => true, 'status_code' => 202,
        ]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://webhook.site/test-uuid'
            && $request->data() === ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Hello']
            && $request->hasHeader('Idempotency-Key', $notification->id));
    }

    public function test_a_2xx_without_the_expected_body_is_still_treated_as_sent(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification);

        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_server_error_schedules_a_retry_with_backoff(): void
    {
        Http::fake(['*' => Http::response('oops', 503)]);
        $notification = Notification::factory()->create();

        $job = $this->runJob($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('provider_error: http 503', $notification->last_error);
        $this->assertTrue($notification->available_at->isFuture());

        // First retry waits 10s + up to 20% jitter.
        $job->assertReleased();
        $this->assertDatabaseHas('notification_attempts', ['notification_id' => $notification->id, 'successful' => false, 'status_code' => 503]);
    }

    public function test_connection_error_is_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertReleased();

        $this->assertSame(NotificationStatus::Pending, $notification->fresh()->status);
    }

    public function test_429_respects_the_retry_after_header(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '120'])]);
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertReleased(delay: 120);
    }

    public function test_client_error_fails_immediately_without_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid number'], 400)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertNotReleased();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame('rejected_by_provider: http 400', $notification->last_error);
        $this->assertNotNull($notification->failed_at);
    }

    public function test_it_fails_after_the_last_attempt(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $notification = Notification::factory()->create(['attempts' => 4]);

        $this->runJob($notification)->assertNotReleased();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(5, $notification->attempts);
    }

    public function test_cancelled_or_already_sent_notifications_are_not_sent(): void
    {
        Http::fake();

        foreach ([NotificationStatus::Cancelled, NotificationStatus::Sent, NotificationStatus::Processing] as $status) {
            $this->runJob(Notification::factory()->status($status)->create());
        }

        Http::assertNothingSent();
    }

    public function test_running_the_same_job_twice_sends_only_once(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'x'], 202)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification);
        $this->runJob($notification);

        Http::assertSentCount(1);
    }

    public function test_job_arriving_before_its_time_is_released_until_then(): void
    {
        Http::fake();
        $notification = Notification::factory()->create(['available_at' => now()->addSeconds(60)]);

        $this->runJob($notification)->assertReleased();

        Http::assertNothingSent();
        $this->assertSame(NotificationStatus::Pending, $notification->fresh()->status);
    }

    public function test_a_manually_retried_notification_keeps_its_attempt_history(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'after-retry'], 202)]);

        $notification = Notification::factory()->status(NotificationStatus::Failed)->create(['attempts' => 1]);
        $notification->deliveryAttempts()->create([
            'attempt' => 1, 'successful' => false, 'status_code' => 404, 'error' => 'rejected_by_provider: http 404', 'duration_ms' => 5,
        ]);

        // The retry endpoint resets the attempt budget...
        $this->assertTrue($notification->resetForRetry());
        $this->runJob($notification);

        // ...but the history keeps counting, so the new attempt does not clash with the old one.
        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame([1, 2], $notification->deliveryAttempts()->pluck('attempt')->all());
    }

    public function test_rate_limited_job_goes_back_to_the_queue_without_using_an_attempt(): void
    {
        Http::fake();
        $notification = Notification::factory()->create(['attempts' => 2]);

        // Another worker took the last slot of this second between the pre-check and the claim.
        $limiter = Mockery::mock(ChannelRateLimiter::class);
        $limiter->shouldReceive('isFull')->andReturnFalse();
        $limiter->shouldReceive('attempt')->andReturnFalse();

        $this->runJob($notification, $limiter)->assertReleased(delay: 1);

        Http::assertNothingSent();
        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(2, $notification->attempts);
    }

    public function test_job_is_released_without_touching_the_database_when_the_second_is_full(): void
    {
        Http::fake();
        $notification = Notification::factory()->create();

        $limiter = Mockery::mock(ChannelRateLimiter::class);
        $limiter->shouldReceive('isFull')->andReturnTrue();
        $limiter->shouldNotReceive('attempt');

        $this->runJob($notification, $limiter)->assertReleased(delay: 1);

        Http::assertNothingSent();
        $this->assertSame(0, $notification->fresh()->attempts);
    }

    private function runJob(Notification $notification, ?ChannelRateLimiter $limiter = null): SendNotification
    {
        $job = (new SendNotification($notification->id, $notification->channel->value))->withFakeQueueInteractions();

        $job->handle(app(NotificationProvider::class), app(RetryPolicy::class), $limiter ?? app(ChannelRateLimiter::class));

        return $job;
    }
}
