<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for notifications that lost their queue job. Runs every minute.
 *
 *  - stuck:   a worker died (OOM, container restart) after claiming it, so it stays "processing";
 *  - never queued: it was saved but pushing the job to Redis failed (dispatched_at is NULL);
 *  - lost:    it is due but has not been picked up long after its job was pushed, e.g. Redis lost
 *             its data. Checked per notification, so constant traffic or delayed (scheduled) jobs
 *             in the same queue do not hide it.
 *
 * Re-dispatching is safe: a duplicated job cannot send twice because of the atomic claim.
 */
class RecoverNotifications extends Command
{
    /** A due notification whose job was pushed this long ago and is still pending is treated as lost. */
    public const LOST_AFTER_MINUTES = 15;

    protected $signature = 'notifications:recover {--limit=1000}';

    protected $description = 'Re-queue notifications that are stuck or lost their queue job';

    public function handle(NotificationService $service): int
    {
        $limit = (int) $this->option('limit');

        $stuck = $this->recoverStuck($service, $limit);
        $neverQueued = $this->redispatch($service, $limit, Notification::query()
            ->where('status', NotificationStatus::Pending)
            ->whereNull('dispatched_at')
            ->where('created_at', '<', now()->subMinute()));
        $lost = $this->redispatch($service, $limit, Notification::query()
            ->where('status', NotificationStatus::Pending)
            ->where('available_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES))
            ->where('dispatched_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES)));

        if ($stuck + $neverQueued + $lost > 0) {
            Log::warning('notifications.recovered', ['stuck' => $stuck, 'never_queued' => $neverQueued, 'lost' => $lost]);
        }

        $this->info("Recovered {$stuck} stuck, {$neverQueued} never queued and {$lost} lost notifications.");

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
     * @param  Builder<Notification>  $query
     */
    private function redispatch(NotificationService $service, int $limit, $query): int
    {
        $notifications = $query->orderBy('available_at')->limit($limit)->get();

        $service->dispatchMany($notifications);

        return $notifications->count();
    }
}
