<?php

namespace App\Services;

use App\Data\NotificationData;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class NotificationService
{
    public function __construct(private readonly Dispatcher $bus) {}

    /**
     * Creates a notification and queues it. If the idempotency key was already used with the
     * same payload, the existing notification is returned and nothing new is queued.
     *
     * @return array{0: Notification, 1: bool} the notification and whether it was created now
     */
    public function create(NotificationData $data): array
    {
        if ($data->idempotencyKey !== null && $existing = $this->findByIdempotencyKey($data->idempotencyKey)) {
            return [$this->ensureSamePayload($existing, $data), false];
        }

        try {
            $notification = Notification::create($this->attributes($data));
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key arrived at the same time and the other one won.
            $existing = $this->findByIdempotencyKey((string) $data->idempotencyKey)
                ?? throw new LogicException('Idempotency key conflict without an existing notification.');

            return [$this->ensureSamePayload($existing, $data), false];
        }

        $this->dispatch($notification);

        return [$notification, true];
    }

    /**
     * Creates up to 1000 notifications in one transaction. Items whose idempotency key
     * already exists (with the same payload) are not created again; the existing
     * notification is returned for them.
     *
     * @param  list<NotificationData>  $items
     * @return array{0: NotificationBatch, 1: Collection<int, Notification>}
     */
    public function createBatch(array $items): array
    {
        $keys = array_values(array_filter(array_map(fn (NotificationData $item) => $item->idempotencyKey, $items)));
        $existing = $keys === []
            ? collect()
            : Notification::whereIn('idempotency_key', $keys)->get()->keyBy('idempotency_key');

        $now = now();
        $rows = [];
        $ids = [];
        $conflicts = [];

        foreach ($items as $item) {
            if ($item->idempotencyKey !== null && $existing->has($item->idempotencyKey)) {
                $found = $existing[$item->idempotencyKey];

                if (! NotificationData::fromModel($found)->samePayloadAs($item)) {
                    $conflicts[] = $item->idempotencyKey;
                }

                $ids[] = $found->id;

                continue;
            }

            $row = $this->attributes($item) + [
                'id' => (string) Str::uuid7(),
                'status' => NotificationStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $rows[] = $row;
            $ids[] = $row['id'];
        }

        if ($conflicts !== []) {
            throw new ConflictHttpException(
                'These idempotency keys were already used with a different payload: '.implode(', ', $conflicts)
            );
        }

        try {
            $batch = DB::transaction(function () use ($rows) {
                $batch = NotificationBatch::create([
                    'total' => count($rows),
                    'correlation_id' => Context::get('correlation_id'),
                ]);

                foreach (array_chunk($rows, 500) as $chunk) {
                    Notification::insert(array_map(fn (array $row) => $row + ['batch_id' => $batch->id], $chunk));
                }

                return $batch;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request inserted one of these idempotency keys in the meantime. Nothing was
            // saved (the transaction rolled back), so the client can safely send the same request again.
            throw new ConflictHttpException('Some idempotency keys are used by a concurrent request. Retry the request.');
        }

        $all = Notification::whereKey($ids)->get()->keyBy('id');

        $this->dispatchMany($all->where('batch_id', $batch->id)->values());

        // Keep the same order as the request.
        $notifications = collect($ids)->map(
            fn (string $id): Notification => $all->get($id) ?? throw new LogicException("Notification {$id} not found.")
        );

        return [$batch, $notifications];
    }

    public function cancel(Notification $notification): bool
    {
        return $notification->cancel();
    }

    /**
     * Manually re-queues a notification that ended up as failed.
     */
    public function retry(Notification $notification): bool
    {
        if (! $notification->resetForRetry()) {
            return false;
        }

        $this->dispatch($notification);

        return true;
    }

    public function dispatch(Notification $notification): void
    {
        $this->dispatchMany(collect([$notification]));
    }

    /**
     * Pushes one job per notification and records when it reached the queue.
     *
     * The notification is already saved in MySQL at this point. If Redis cannot be reached the
     * error is logged and the row keeps dispatched_at = NULL; notifications:recover picks it up
     * once Redis is back, so the API does not lose a request it has already accepted.
     *
     * @param  Collection<int, Notification>  $notifications
     */
    public function dispatchMany(Collection $notifications): void
    {
        $dispatched = [];

        foreach ($notifications as $notification) {
            $job = (new SendNotification($notification->id, $notification->channel->value))
                ->onQueue($notification->priority->queue());

            if ($notification->available_at->isFuture()) {
                $job->delay($notification->available_at);
            }

            try {
                $this->bus->dispatch($job);
                $dispatched[] = $notification->id;
            } catch (Throwable $e) {
                Log::error('notification.dispatch_failed', [
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (array_chunk($dispatched, 500) as $chunk) {
            // toBase(): only dispatched_at changes; updated_at keeps tracking status changes.
            Notification::whereKey($chunk)->toBase()->update(['dispatched_at' => now()]);
        }
    }

    /**
     * The same idempotency key with a different payload is a client error. Returning the old
     * notification silently would hide it, so it is rejected with 409.
     */
    private function ensureSamePayload(Notification $existing, NotificationData $data): Notification
    {
        if (! NotificationData::fromModel($existing)->samePayloadAs($data)) {
            throw new ConflictHttpException(
                "Idempotency key '{$data->idempotencyKey}' was already used with a different payload."
            );
        }

        return $existing;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(NotificationData $data): array
    {
        return [
            'channel' => $data->channel->value,
            'priority' => $data->priority->value,
            'recipient' => $data->recipient,
            'content' => $data->content,
            'idempotency_key' => $data->idempotencyKey,
            'scheduled_at' => $data->scheduledAt,
            'available_at' => $data->scheduledAt ?? now(),
            'correlation_id' => Context::get('correlation_id'),
        ];
    }

    private function findByIdempotencyKey(string $key): ?Notification
    {
        return Notification::where('idempotency_key', $key)->first();
    }
}
