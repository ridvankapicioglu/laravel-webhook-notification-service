<?php

namespace App\Jobs;

use App\Delivery\ChannelRateLimiter;
use App\Delivery\DeliveryResult;
use App\Delivery\NotificationProvider;
use App\Delivery\RetryPolicy;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Unlimited queue attempts: rate limit waits and retries release the job back to the queue.
     * The real delivery attempt budget is kept in the notifications.attempts column.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $notificationId,
        public readonly string $channel,
    ) {}

    public function handle(NotificationProvider $provider, RetryPolicy $retryPolicy, ChannelRateLimiter $rateLimiter): void
    {
        // The channel's window is already full: come back a moment later without touching the database.
        if ($rateLimiter->isFull($this->channel)) {
            $this->release(1);

            return;
        }

        $notification = Notification::find($this->notificationId);

        if ($notification === null || $notification->status !== NotificationStatus::Pending) {
            // Cancelled, already sent, or picked up by another worker.
            return;
        }

        Context::add([
            'notification_id' => $notification->id,
            'correlation_id' => $notification->correlation_id,
        ]);

        if ($notification->available_at->isFuture()) {
            $this->release((int) ceil(now()->diffInSeconds($notification->available_at)));

            return;
        }

        if (! $notification->claim()) {
            return;
        }

        // History numbering keeps going after a manual retry (which resets the attempt budget),
        // so it is not taken from $notification->attempts. Safe without a lock: we own the claim.
        $attemptNumber = (int) $notification->deliveryAttempts()->max('attempt') + 1;

        // Taken last, with nothing but the HTTP call after it, so the limit applies to when calls start.
        if (! $rateLimiter->attempt($this->channel)) {
            $notification->releaseClaim();
            $this->release(1);

            return;
        }

        $startedAt = now();
        $result = $provider->send($notification);

        $notification->deliveryAttempts()->create([
            'attempt' => $attemptNumber,
            'successful' => $result->successful,
            'status_code' => $result->statusCode,
            'error' => $result->error,
            'duration_ms' => $result->durationMs,
            // When the request was sent, not when the answer came back.
            'created_at' => $startedAt,
        ]);

        $this->handleResult($notification, $result, $retryPolicy);
    }

    public function failed(?Throwable $exception): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification?->markFailed('unexpected_error')) {
            Log::error('notification.failed', [
                'notification_id' => $notification->id,
                'reason' => 'unexpected_error',
                'exception' => $exception?->getMessage(),
            ]);
        }
    }

    private function handleResult(Notification $notification, DeliveryResult $result, RetryPolicy $retryPolicy): void
    {
        $logContext = [
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'attempt' => $notification->attempts,
            'status_code' => $result->statusCode,
            'duration_ms' => $result->durationMs,
        ];

        if ($result->successful) {
            $notification->markSent($result->messageId);
            Log::info('notification.sent', $logContext + ['provider_message_id' => $result->messageId]);

            return;
        }

        if ($retryPolicy->shouldRetry($result, $notification->attempts)) {
            $delay = $retryPolicy->delayFor($notification->attempts, $result->retryAfter);

            $notification->scheduleRetry((string) $result->error, $delay);
            $this->release($delay);

            Log::warning('notification.retry_scheduled', $logContext + [
                'error' => $result->error,
                'retry_in_seconds' => $delay,
            ]);

            return;
        }

        $notification->markFailed((string) $result->error);

        Log::error('notification.failed', $logContext + [
            'error' => $result->error,
            'reason' => $result->retryable ? 'max_attempts_reached' : 'rejected_by_provider',
        ]);
    }
}
