<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
use Throwable;

class MonitoringController extends Controller
{
    public function metrics(Request $request, MetricsService $metrics): JsonResponse
    {
        $window = min(max((int) $request->query('window', 60), 1), 1440);

        return response()->json($metrics->snapshot($window));
    }

    public function health(MasterSupervisorRepository $supervisors): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            // Horizon masters keep a heartbeat in Redis and disappear from this list soon after they stop.
            // Without a running one, notifications are accepted but never delivered.
            'workers' => $this->check(function () use ($supervisors) {
                if (collect($supervisors->all())->where('status', 'running')->isEmpty()) {
                    throw new RuntimeException('No running Horizon supervisor.');
                }
            }),
        ];

        $healthy = ! in_array('down', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'down',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'up';
        } catch (Throwable $e) {
            Log::warning('health_check.failed', ['error' => $e->getMessage()]);

            return 'down';
        }
    }
}
