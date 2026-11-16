<?php

namespace App\Http\Resources;

use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'idempotency_key' => $this->idempotency_key,
            'channel' => $this->channel,
            'priority' => $this->priority,
            'recipient' => $this->recipient,
            'content' => $this->content,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_error' => $this->last_error,
            'provider_message_id' => $this->provider_message_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'delivery_attempts' => $this->whenLoaded('deliveryAttempts', fn () => $this->deliveryAttempts->map(
                fn (NotificationAttempt $attempt) => [
                    'attempt' => $attempt->attempt,
                    'successful' => $attempt->successful,
                    'status_code' => $attempt->status_code,
                    'error' => $attempt->error,
                    'duration_ms' => $attempt->duration_ms,
                    'created_at' => $attempt->created_at->toIso8601String(),
                ]
            )),
        ];
    }
}
