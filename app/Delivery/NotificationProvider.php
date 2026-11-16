<?php

namespace App\Delivery;

use App\Models\Notification;

interface NotificationProvider
{
    public function send(Notification $notification): DeliveryResult;
}
