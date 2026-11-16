<?php

namespace App\Http\Controllers\Api;

use App\Data\NotificationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreBatchRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $notifications = Notification::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['priority'] ?? null, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($filters['batch_id'] ?? null, fn ($q, $batchId) => $q->where('batch_id', $batchId))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->utc()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->utc()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    public function store(StoreNotificationRequest $request): JsonResponse
    {
        [$notification, $created] = $this->notifications->create(NotificationData::fromArray($request->validated()));

        // 202: accepted for asynchronous delivery. 200: same idempotency key, nothing new was created.
        return NotificationResource::make($notification)
            ->response()
            ->setStatusCode($created ? 202 : 200);
    }

    public function storeBatch(StoreBatchRequest $request): JsonResponse
    {
        $items = array_map(NotificationData::fromArray(...), $request->validated('notifications'));

        [$batch, $notifications] = $this->notifications->createBatch($items);

        return response()->json([
            'data' => [
                'batch_id' => $batch->id,
                'total' => count($items),
                'created' => $batch->total,
                'notifications' => $notifications->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'status' => $n->status,
                    'idempotency_key' => $n->idempotency_key,
                    'duplicate' => $n->batch_id !== $batch->id,
                ]),
            ],
        ], 202);
    }

    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification->load('deliveryAttempts'));
    }

    public function cancel(Notification $notification): JsonResponse|NotificationResource
    {
        if (! $this->notifications->cancel($notification)) {
            return $this->conflict("Only pending notifications can be cancelled, current status is '{$notification->fresh()?->status->value}'.");
        }

        return NotificationResource::make($notification);
    }

    public function retry(Notification $notification): JsonResponse
    {
        if (! $this->notifications->retry($notification)) {
            return $this->conflict("Only failed notifications can be retried, current status is '{$notification->fresh()?->status->value}'.");
        }

        return NotificationResource::make($notification)->response()->setStatusCode(202);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
