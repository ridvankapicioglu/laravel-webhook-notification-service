<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(NotificationStatus::class)],
            'channel' => ['sometimes', Rule::enum(Channel::class)],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'batch_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
