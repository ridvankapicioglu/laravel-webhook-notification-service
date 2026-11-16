<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for notifications that lost their queue job:
 *  - a worker died (OOM, container restart) after claiming the notification, so it stays "processing";
 *  - redis lost its data, so "pending" notifications have no job left in the queue.
 *
 * Re-dispatching is safe: a duplicated job cannot send twice because of the atomic claim.
 */
class RecoverNotifications extends Command
{
    protected $signature = 'notifications:recover {--limit=1000}';

    protected $description = 'Re-queue notifications that are stuck or lost their queue job';

    public function handle(NotificationService $service, QueueFactory $queue): int
    {
        $limit = (int) $this->option('limit');

        $stuck = $this->recoverStuck($service, $limit);
        $lost = $this->recoverLost($service, $queue, $limit);

        if ($stuck + $lost > 0) {
            Log::warning('notifications.recovered', ['stuck' => $stuck, 'lost' => $lost]);
        }

        $this->info("Recovered {$stuck} stuck and {$lost} lost notifications.");

        return self::SUCCESS;
    }

    private function recoverStuck(NotificationService $service, int $limit): int
    {
        $stuck = Notification::where('status', NotificationStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes(config('notifications.stuck_after_minutes')))
            ->limit($limit)
            ->get();

        $recovered = 0;

        foreach ($stuck as $notification) {
            // We do not know if the provider got the message before the worker died. The attempt was
            // already counted, so if the budget is used up the notification is marked as failed.
            if ($notification->attempts >= config('notifications.max_attempts')) {
                $notification->markFailed('worker_lost');

                continue;
            }

            if ($notification->scheduleRetry('worker_lost', 0)) {
                $service->dispatch($notification);
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * Pending notifications that are due, while the queue of their priority is completely empty,
     * can only mean the jobs were lost. Under normal load the queue is not empty, so this
     * never creates duplicate jobs for a large backlog.
     */
    private function recoverLost(NotificationService $service, QueueFactory $queue, int $limit): int
    {
        $recovered = 0;

        foreach (Priority::cases() as $priority) {
            if ($queue->connection()->size($priority->queue()) > 0) {
                continue;
            }

            Notification::where('status', NotificationStatus::Pending)
                ->where('priority', $priority)
                ->where('available_at', '<', now()->subMinute())
                ->limit($limit)
                ->get()
                ->each(function (Notification $notification) use ($service, &$recovered) {
                    $service->dispatch($notification);
                    $recovered++;
                });
        }

        return $recovered;
    }
}
