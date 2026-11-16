<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use Illuminate\Validation\Rule;

/**
 * Validation rules for a single notification, shared by the single and the batch endpoints.
 * Recipient format and content length depend on the channel.
 */
class NotificationRules
{
    /**
     * @return array<string, mixed>
     */
    public static function for(mixed $channel, string $prefix = ''): array
    {
        $channel = is_string($channel) ? Channel::tryFrom($channel) : null;

        return [
            $prefix.'channel' => ['required', Rule::enum(Channel::class)],
            $prefix.'priority' => ['sometimes', Rule::enum(Priority::class)],
            $prefix.'recipient' => ['required', 'string', ...self::recipientRules($channel)],
            $prefix.'content' => ['required', 'string', 'max:'.($channel?->maxContentLength() ?? 10000)],
            $prefix.'idempotency_key' => ['nullable', 'string', 'max:128'],
            $prefix.'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function recipientRules(?Channel $channel): array
    {
        return match ($channel) {
            // E.164 phone number, e.g. +905551234567
            Channel::Sms => ['regex:/^\+[1-9]\d{7,14}$/'],
            Channel::Email => ['email', 'max:254'],
            Channel::Push => ['max:512'],
            null => ['max:512'],
        };
    }
}
