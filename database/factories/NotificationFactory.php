<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'channel' => Channel::Sms,
            'priority' => Priority::Normal,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'content' => fake()->sentence(),
            'status' => NotificationStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
        ];
    }

    public function status(NotificationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function email(): static
    {
        return $this->state(fn () => ['channel' => Channel::Email, 'recipient' => fake()->safeEmail()]);
    }
}
