<?php

namespace App\Delivery;

use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends notifications to the external provider (webhook.site in the assessment setup).
 */
class WebhookProvider implements NotificationProvider
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public function send(Notification $notification): DeliveryResult
    {
        $startedAt = hrtime(true);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->withHeaders([
                    // Lets a provider that supports it drop duplicates if we ever retry after a timeout.
                    'Idempotency-Key' => $notification->id,
                    'X-Correlation-ID' => (string) $notification->correlation_id,
                ])
                ->post($this->url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            return DeliveryResult::retryable(null, 'connection_error: '.$e->getMessage(), $this->elapsedMs($startedAt));
        }

        return $this->toResult($response, $this->elapsedMs($startedAt));
    }

    private function toResult(Response $response, int $durationMs): DeliveryResult
    {
        $status = $response->status();

        if ($response->successful()) {
            // Any 2xx means the provider has the message. We never retry it, even if the body is not
            // what we expected, otherwise the user could receive the same message twice.
            $messageId = $response->json('messageId');

            return DeliveryResult::accepted($status, is_string($messageId) ? $messageId : null, $durationMs);
        }

        if ($status === 429) {
            return DeliveryResult::retryable($status, 'rate_limited_by_provider', $durationMs, $this->retryAfter($response));
        }

        if ($status === 408 || $response->serverError()) {
            return DeliveryResult::retryable($status, "provider_error: http {$status}", $durationMs);
        }

        return DeliveryResult::rejected($status, "rejected_by_provider: http {$status}", $durationMs);
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = strtotime($header);

        return $date === false ? null : max(0, $date - time());
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
