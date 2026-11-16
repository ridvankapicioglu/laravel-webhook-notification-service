<?php

namespace App\Data;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Models\Notification;
use Carbon\CarbonImmutable;

final readonly class NotificationData
{
    public function __construct(
        public Channel $channel,
        public Priority $priority,
        public string $recipient,
        public string $content,
        public ?string $idempotencyKey = null,
        public ?CarbonImmutable $scheduledAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  already validated request data
     */
    public static function fromArray(array $input): self
    {
        return new self(
            channel: Channel::from($input['channel']),
            priority: Priority::from($input['priority'] ?? Priority::Normal->value),
            recipient: $input['recipient'],
            content: $input['content'],
            idempotencyKey: $input['idempotency_key'] ?? null,
            // Whole seconds, like the database column, so a stored request compares equal to its retry.
            scheduledAt: isset($input['scheduled_at'])
                ? CarbonImmutable::parse($input['scheduled_at'])->utc()->startOfSecond()
                : null,
        );
    }

    /**
     * Rebuilds the request payload from a stored notification.
     */
    public static function fromModel(Notification $notification): self
    {
        return new self(
            channel: $notification->channel,
            priority: $notification->priority,
            recipient: $notification->recipient,
            content: $notification->content,
            idempotencyKey: $notification->idempotency_key,
            scheduledAt: $notification->scheduled_at?->toImmutable()->utc(),
        );
    }

    /**
     * Whether a request carries the same payload as another one. Used to tell a plain retry with
     * an idempotency key apart from the same key reused for a different message.
     */
    public function samePayloadAs(self $other): bool
    {
        return $this->payload() === $other->payload();
    }

    /**
     * @return array<int, string|null>
     */
    private function payload(): array
    {
        return [
            $this->channel->value,
            $this->priority->value,
            $this->recipient,
            $this->content,
            $this->scheduledAt?->toIso8601String(),
        ];
    }
}
