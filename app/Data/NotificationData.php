<?php

namespace App\Data;

use App\Enums\Channel;
use App\Enums\Priority;
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
            scheduledAt: isset($input['scheduled_at']) ? CarbonImmutable::parse($input['scheduled_at'])->utc() : null,
        );
    }
}
