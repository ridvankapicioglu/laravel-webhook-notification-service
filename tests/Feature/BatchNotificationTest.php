<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BatchNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_creates_a_batch_and_queues_every_notification(): void
    {
        $response = $this->postJson('/api/notifications/batch', [
            'notifications' => [
                ['recipient' => '+905551234567', 'channel' => 'sms', 'content' => 'A', 'priority' => 'high'],
                ['recipient' => 'user@example.com', 'channel' => 'email', 'content' => 'B'],
                ['recipient' => 'device-token-123', 'channel' => 'push', 'content' => 'C', 'priority' => 'low'],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.created', 3)
            ->assertJsonCount(3, 'data.notifications');

        $batchId = $response->json('data.batch_id');
        $this->assertSame(3, Notification::where('batch_id', $batchId)->count());

        Queue::assertPushedOn('high', SendNotification::class);
        Queue::assertPushedOn('normal', SendNotification::class);
        Queue::assertPushedOn('low', SendNotification::class);
    }

    public function test_it_accepts_1000_notifications(): void
    {
        $this->postJson('/api/notifications/batch', ['notifications' => $this->items(1000)])
            ->assertStatus(202)
            ->assertJsonPath('data.created', 1000);

        $this->assertSame(1000, Notification::count());
        Queue::assertPushed(SendNotification::class, 1000);
    }

    public function test_it_rejects_more_than_1000_notifications(): void
    {
        $this->postJson('/api/notifications/batch', ['notifications' => $this->items(1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notifications']);

        $this->assertSame(0, Notification::count());
    }

    public function test_one_invalid_item_rejects_the_whole_batch(): void
    {
        $items = $this->items(3);
        $items[1]['recipient'] = 'invalid';

        $this->postJson('/api/notifications/batch', ['notifications' => $items])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notifications.1.recipient']);

        $this->assertSame(0, Notification::count());
        Queue::assertNothingPushed();
    }

    public function test_batch_status_returns_counts_per_status(): void
    {
        $batchId = $this->postJson('/api/notifications/batch', ['notifications' => $this->items(3)])->json('data.batch_id');

        Notification::where('batch_id', $batchId)->first()->update(['status' => NotificationStatus::Sent]);

        $this->getJson("/api/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.statuses.pending', 2)
            ->assertJsonPath('data.statuses.sent', 1)
            ->assertJsonPath('data.completed', false);
    }

    public function test_notifications_can_be_listed_by_batch(): void
    {
        $batchId = $this->postJson('/api/notifications/batch', ['notifications' => $this->items(2)])->json('data.batch_id');
        Notification::factory()->create();

        $this->getJson("/api/notifications?batch_id={$batchId}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_unknown_batch_returns_404(): void
    {
        $this->getJson('/api/batches/0198c3a4-0000-7000-8000-000000000000')->assertNotFound();
    }

    /**
     * @return list<array<string, string>>
     */
    private function items(int $count): array
    {
        return array_map(fn (int $i) => [
            'recipient' => '+90555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'channel' => 'sms',
            'content' => "Message {$i}",
        ], range(1, $count));
    }
}
