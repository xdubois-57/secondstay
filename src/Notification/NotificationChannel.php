<?php

declare(strict_types=1);

namespace SecondStay\Notification;

enum NotificationChannel: string
{
    case Email = 'email';
    case Push = 'push';
}
