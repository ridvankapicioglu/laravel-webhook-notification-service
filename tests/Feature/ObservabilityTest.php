<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_correlation_id_from_the_client_is_returned_and_stored(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/notifications', [
            'recipient' => '+905551234567', 'channel' => 'sms', 'content' => 'Hi',
        ], ['X-Correlation-ID' => 'checkout-flow-123']);

        $response->assertHeader('X-Correlation-ID', 'checkout-flow-123');
        $this->assertSame('checkout-flow-123', Notification::first()->correlation_id);
    }

    public function test_a_correlation_id_is_generated_when_missing_or_invalid(): void
    {
        $this->getJson('/api/notifications')->assertHeader('X-Correlation-ID');

        $id = $this->getJson('/api/notifications', ['X-Correlation-ID' => str_repeat('x', 200)])
            ->headers->get('X-Correlation-ID');

        $this->assertTrue(Str::isUuid($id));
    }

    public function test_metrics_report_status_counts_rates_and_latency(): void
    {
        Notification::factory()->count(3)->status(NotificationStatus::Sent)->create([
            'created_at' => now()->subSeconds(2), 'sent_at' => now(),
        ]);
        Notification::factory()->status(NotificationStatus::Failed)->create();
        Notification::factory()->count(2)->create();

        $sent = Notification::where('status', NotificationStatus::Sent)->first();
        foreach ([100, 200, 300] as $i => $ms) {
            $sent->deliveryAttempts()->create(['attempt' => $i + 1, 'successful' => true, 'status_code' => 202, 'duration_ms' => $ms]);
        }

        $this->getJson('/api/metrics')
            ->assertOk()
            ->assertJsonStructure([
                'window_minutes',
                'queue_depth' => ['high', 'normal', 'low'],
                'notifications_by_status' => ['pending', 'processing', 'sent', 'failed', 'cancelled'],
                'delivery' => ['overall', 'by_channel' => ['sms', 'email', 'push']],
                'latency_ms' => ['provider', 'end_to_end'],
                'generated_at',
            ])
            ->assertJsonPath('notifications_by_status.sent', 3)
            ->assertJsonPath('notifications_by_status.pending', 2)
            ->assertJsonPath('delivery.overall.sent', 3)
            ->assertJsonPath('delivery.overall.failed', 1)
            ->assertJsonPath('delivery.overall.success_rate', 0.75)
            ->assertJsonPath('delivery.by_channel.'.Channel::Email->value.'.success_rate', null)
            ->assertJsonPath('latency_ms.provider.samples', 3)
            ->assertJsonPath('latency_ms.provider.avg', 200)
            ->assertJsonPath('latency_ms.provider.max', 300);
    }

    public function test_health_is_ok_when_database_and_redis_are_up(): void
    {
        Redis::shouldReceive('connection->ping')->andReturn(true);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['database' => 'up', 'redis' => 'up']]);
    }

    public function test_health_returns_503_when_a_dependency_is_down(): void
    {
        Redis::shouldReceive('connection->ping')->andThrow(new RuntimeException('Connection refused'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJson(['status' => 'down', 'checks' => ['database' => 'up', 'redis' => 'down']]);
    }
}
