<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string|null $batch_id
 * @property string|null $idempotency_key
 * @property Channel $channel
 * @property Priority $priority
 * @property string $recipient
 * @property string $content
 * @property NotificationStatus $status
 * @property int $attempts
 * @property Carbon|null $scheduled_at
 * @property Carbon $available_at
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property string|null $correlation_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'priority' => Priority::class,
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /**
     * @return HasMany<NotificationAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class)->orderBy('attempt');
    }

    /**
     * Moves the notification from "pending" to "processing" with a single conditional update.
     * Only one worker can win this, so a duplicated job can never send the same message twice.
     */
    public function claim(): bool
    {
        return $this->transition(NotificationStatus::Pending, [
            'status' => NotificationStatus::Processing,
            'attempts' => DB::raw('attempts + 1'),
        ], fn ($query) => $query->where('available_at', '<=', now()));
    }

    /**
     * Undoes a claim when the provider was not called (rate limit), so it does not use up an attempt.
     */
    public function releaseClaim(): bool
    {
        return $this->transition(NotificationStatus::Processing, [
            'status' => NotificationStatus::Pending,
            'attempts' => DB::raw('attempts - 1'),
        ]);
    }

    public function markSent(?string $providerMessageId): bool
    {
        return $this->transition(NotificationStatus::Processing, [
            'status' => NotificationStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'last_error' => null,
            'sent_at' => now(),
        ]);
    }

    public function scheduleRetry(string $error, int $delaySeconds): bool
    {
        return $this->transition(NotificationStatus::Processing, [
            'status' => NotificationStatus::Pending,
            'last_error' => $error,
            'available_at' => now()->addSeconds($delaySeconds),
        ]);
    }

    public function markFailed(string $error): bool
    {
        return $this->transition(NotificationStatus::Processing, [
            'status' => NotificationStatus::Failed,
            'last_error' => $error,
            'failed_at' => now(),
        ]);
    }

    public function cancel(): bool
    {
        return $this->transition(NotificationStatus::Pending, [
            'status' => NotificationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Puts a failed notification back in the queue with a fresh attempt budget.
     */
    public function resetForRetry(): bool
    {
        return $this->transition(NotificationStatus::Failed, [
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
            'failed_at' => null,
            'available_at' => now(),
        ]);
    }

    /**
     * Updates the row only if it is still in the expected status, so concurrent
     * workers / API calls cannot overwrite each other's result.
     *
     * @param  array<string, mixed>  $values
     */
    private function transition(NotificationStatus $from, array $values, ?callable $constraint = null): bool
    {
        $query = static::query()->whereKey($this->id)->where('status', $from);

        if ($constraint !== null) {
            $constraint($query);
        }

        $updated = $query->update($values + ['updated_at' => now()]) === 1;

        if ($updated) {
            $this->refresh();
        }

        return $updated;
    }
}
