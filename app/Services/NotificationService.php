<?php

namespace App\Services;

use App\Data\NotificationData;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class NotificationService
{
    /**
     * Creates a notification and queues it. If the idempotency key was already used,
     * the existing notification is returned and nothing new is queued.
     *
     * @return array{0: Notification, 1: bool} the notification and whether it was created now
     */
    public function create(NotificationData $data): array
    {
        if ($data->idempotencyKey !== null && $existing = $this->findByIdempotencyKey($data->idempotencyKey)) {
            return [$existing, false];
        }

        try {
            $notification = Notification::create($this->attributes($data));
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same key arrived at the same time and the other one won.
            return [$this->findByIdempotencyKey((string) $data->idempotencyKey), false];
        }

        $this->dispatch($notification);

        return [$notification, true];
    }

    /**
     * Creates up to 1000 notifications in one transaction. Items whose idempotency key
     * already exists are not created again; the existing notification is returned for them.
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

        foreach ($items as $item) {
            if ($item->idempotencyKey !== null && $existing->has($item->idempotencyKey)) {
                $ids[] = $existing[$item->idempotencyKey]->id;

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

        $all->where('batch_id', $batch->id)->each(fn (Notification $notification) => $this->dispatch($notification));

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
        $job = SendNotification::dispatch($notification->id, $notification->channel->value)
            ->onQueue($notification->priority->queue());

        if ($notification->available_at->isFuture()) {
            $job->delay($notification->available_at);
        }
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
