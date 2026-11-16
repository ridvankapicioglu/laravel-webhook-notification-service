<?php

namespace Tests\Feature;

use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_creates_a_notification_and_queues_it_on_its_priority_queue(): void
    {
        $response = $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => 'Your order has shipped',
            'priority' => 'high',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.priority', 'high');

        $id = $response->json('data.id');
        $this->assertDatabaseHas('notifications', ['id' => $id, 'status' => 'pending']);

        Queue::assertPushedOn('high', SendNotification::class, fn ($job) => $job->notificationId === $id);
    }

    public function test_priority_defaults_to_normal(): void
    {
        $this->postJson('/api/notifications', [
            'recipient' => 'user@example.com',
            'channel' => 'email',
            'content' => 'Hello',
        ])->assertStatus(202)->assertJsonPath('data.priority', 'normal');

        Queue::assertPushedOn('normal', SendNotification::class);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson('/api/notifications', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient', 'channel', 'content']);

        Queue::assertNothingPushed();
    }

    public function test_unknown_channel_and_priority_are_rejected(): void
    {
        $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'fax',
            'content' => 'Hi',
            'priority' => 'urgent',
        ])->assertStatus(422)->assertJsonValidationErrors(['channel', 'priority']);
    }

    public function test_recipient_must_match_the_channel(): void
    {
        $this->postJson('/api/notifications', [
            'recipient' => 'not-a-phone',
            'channel' => 'sms',
            'content' => 'Hi',
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient']);

        $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'email',
            'content' => 'Hi',
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient']);
    }

    public function test_content_length_is_limited_per_channel(): void
    {
        $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => str_repeat('a', 161),
        ])->assertStatus(422)->assertJsonValidationErrors(['content']);

        $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => str_repeat('a', 160),
        ])->assertStatus(202);
    }

    public function test_it_can_schedule_a_notification_for_later(): void
    {
        $at = now()->addHour()->startOfSecond();

        $response = $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => 'Flash sale starts now',
            'scheduled_at' => $at->toIso8601String(),
        ])->assertStatus(202);

        $notification = Notification::findOrFail($response->json('data.id'));
        $this->assertTrue($notification->available_at->equalTo($at));

        Queue::assertPushed(SendNotification::class, fn ($job) => $job->delay !== null);
    }

    public function test_scheduled_at_must_be_in_the_future(): void
    {
        $this->postJson('/api/notifications', [
            'recipient' => '+905551234567',
            'channel' => 'sms',
            'content' => 'Hi',
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
    }
}
