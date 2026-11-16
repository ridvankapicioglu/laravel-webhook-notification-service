<?php

namespace App\Services;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Collection;

class MetricsService
{
    /** Latency stats are calculated from at most this many recent rows to keep the endpoint cheap. */
    private const SAMPLE_LIMIT = 5000;

    public function __construct(private readonly QueueFactory $queue) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $windowMinutes = 60): array
    {
        $since = now()->subMinutes($windowMinutes);

        return [
            'window_minutes' => $windowMinutes,
            'queue_depth' => $this->queueDepth(),
            'notifications_by_status' => $this->countsByStatus(),
            'delivery' => $this->deliveryRates($since),
            'latency_ms' => [
                'provider' => $this->stats(
                    NotificationAttempt::where('created_at', '>=', $since)
                        ->latest('created_at')
                        ->limit(self::SAMPLE_LIMIT)
                        ->pluck('duration_ms')
                ),
                'end_to_end' => $this->stats(
                    Notification::where('status', NotificationStatus::Sent)
                        ->where('updated_at', '>=', $since)
                        ->whereNull('scheduled_at')
                        ->latest('updated_at')
                        ->limit(self::SAMPLE_LIMIT)
                        ->get(['created_at', 'sent_at'])
                        ->map(fn (Notification $n) => (int) $n->created_at->diffInMilliseconds($n->sent_at))
                ),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function queueDepth(): array
    {
        $connection = $this->queue->connection();

        return collect(Priority::cases())->mapWithKeys(function (Priority $priority) use ($connection) {
            $queue = $priority->queue();

            if (! $connection instanceof RedisQueue) {
                return [$queue => ['total' => $connection->size($queue)]];
            }

            return [$queue => [
                'ready' => $connection->pendingSize($queue),
                'delayed' => $connection->delayedSize($queue),
                'reserved' => $connection->reservedSize($queue),
                'total' => $connection->size($queue),
            ]];
        })->all();
    }

    /**
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        $counts = Notification::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect(NotificationStatus::cases())
            ->mapWithKeys(fn (NotificationStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])
            ->all();
    }

    /**
     * Success / failure rate of notifications that reached a final state inside the window.
     *
     * @return array<string, mixed>
     */
    private function deliveryRates(\DateTimeInterface $since): array
    {
        $rows = Notification::selectRaw('channel, status, count(*) as total')
            ->whereIn('status', [NotificationStatus::Sent, NotificationStatus::Failed])
            ->where('updated_at', '>=', $since)
            ->groupBy('channel', 'status')
            ->get();

        $rate = function (Collection $rows): array {
            $sent = (int) $rows->where('status', NotificationStatus::Sent)->sum('total');
            $failed = (int) $rows->where('status', NotificationStatus::Failed)->sum('total');
            $total = $sent + $failed;

            return [
                'sent' => $sent,
                'failed' => $failed,
                'success_rate' => $total > 0 ? round($sent / $total, 4) : null,
                'failure_rate' => $total > 0 ? round($failed / $total, 4) : null,
            ];
        };

        return [
            'overall' => $rate($rows),
            'by_channel' => collect(Channel::cases())
                ->mapWithKeys(fn (Channel $channel) => [$channel->value => $rate($rows->where('channel', $channel))])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $values
     * @return array<string, int|null>
     */
    private function stats(Collection $values): array
    {
        if ($values->isEmpty()) {
            return ['samples' => 0, 'avg' => null, 'p50' => null, 'p95' => null, 'max' => null];
        }

        $sorted = $values->sort()->values();
        $percentile = fn (float $p) => (int) $sorted[(int) max(0, ceil($p * $sorted->count()) - 1)];

        return [
            'samples' => $sorted->count(),
            'avg' => (int) round($sorted->avg()),
            'p50' => $percentile(0.50),
            'p95' => $percentile(0.95),
            'max' => (int) $sorted->last(),
        ];
    }
}
