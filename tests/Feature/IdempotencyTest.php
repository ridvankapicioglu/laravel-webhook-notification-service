<?php

namespace Tests\Feature;

use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'recipient' => '+905551234567',
        'channel' => 'sms',
        'content' => 'Your code is 1234',
        'idempotency_key' => 'order-42-shipped',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_same_key_returns_the_existing_notification_and_does_not_queue_again(): void
    {
        $first = $this->postJson('/api/notifications', $this->payload)->assertStatus(202);
        $second = $this->postJson('/api/notifications', $this->payload)->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Notification::count());
        Queue::assertPushed(SendNotification::class, 1);
    }

    public function test_key_can_be_sent_as_a_header(): void
    {
        $payload = array_diff_key($this->payload, ['idempotency_key' => true]);

        $first = $this->postJson('/api/notifications', $payload, ['Idempotency-Key' => 'abc-1'])->assertStatus(202);
        $second = $this->postJson('/api/notifications', $payload, ['Idempotency-Key' => 'abc-1'])->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('abc-1', $first->json('data.idempotency_key'));
    }

    public function test_without_a_key_every_request_creates_a_new_notification(): void
    {
        $payload = array_diff_key($this->payload, ['idempotency_key' => true]);

        $this->postJson('/api/notifications', $payload)->assertStatus(202);
        $this->postJson('/api/notifications', $payload)->assertStatus(202);

        $this->assertSame(2, Notification::count());
    }

    public function test_batch_skips_items_whose_key_already_exists(): void
    {
        $existingId = $this->postJson('/api/notifications', $this->payload)->json('data.id');

        $response = $this->postJson('/api/notifications/batch', [
            'notifications' => [
                $this->payload,
                ['recipient' => '+905559999999', 'channel' => 'sms', 'content' => 'New', 'idempotency_key' => 'new-key'],
            ],
        ])->assertStatus(202);

        $response->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.notifications.0.id', $existingId)
            ->assertJsonPath('data.notifications.0.duplicate', true)
            ->assertJsonPath('data.notifications.1.duplicate', false);

        $this->assertSame(2, Notification::count());
        Queue::assertPushed(SendNotification::class, 2);
    }

    public function test_batch_rejects_the_same_key_twice_in_one_request(): void
    {
        $this->postJson('/api/notifications/batch', [
            'notifications' => [$this->payload, $this->payload],
        ])->assertStatus(422)->assertJsonValidationErrors(['notifications.0.idempotency_key']);
    }
}
