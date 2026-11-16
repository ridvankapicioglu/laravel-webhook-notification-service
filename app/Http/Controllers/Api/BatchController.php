<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\NotificationBatch;
use Illuminate\Http\JsonResponse;

class BatchController extends Controller
{
    public function show(NotificationBatch $batch): JsonResponse
    {
        $counts = $batch->notifications()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = collect(NotificationStatus::cases())
            ->mapWithKeys(fn (NotificationStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]);

        return response()->json([
            'data' => [
                'id' => $batch->id,
                'total' => $batch->total,
                'statuses' => $byStatus,
                'completed' => $byStatus->only(['sent', 'failed', 'cancelled'])->sum() === $batch->total,
                'created_at' => $batch->created_at->toIso8601String(),
                'notifications_url' => url("/api/notifications?batch_id={$batch->id}"),
            ],
        ]);
    }
}
