<?php

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    public function maxContentLength(): int
    {
        return config("notifications.content_limits.{$this->value}");
    }
}
