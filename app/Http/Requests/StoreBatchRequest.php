<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBatchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'notifications' => ['required', 'array', 'list', 'min:1', 'max:'.config('notifications.batch_limit')],
            'notifications.*' => ['array'],
            // Defined once on the wildcard (not per item) so that "distinct" can compare all items.
            'notifications.*.idempotency_key' => ['nullable', 'string', 'max:128', 'distinct'],
        ];

        $items = $this->input('notifications');

        // Do not build per-item rules for an oversized payload, the max rule above already rejects it.
        if (! is_array($items) || count($items) > config('notifications.batch_limit')) {
            return $rules;
        }

        foreach ($items as $index => $item) {
            $channel = is_array($item) ? ($item['channel'] ?? null) : null;
            $itemRules = NotificationRules::for($channel, "notifications.{$index}.");
            unset($itemRules["notifications.{$index}.idempotency_key"]);

            $rules += $itemRules;
        }

        return $rules;
    }
}
