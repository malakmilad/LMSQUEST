<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Expired = 'expired';
}
