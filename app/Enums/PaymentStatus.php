<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Succeeded = 'succeeded';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
}
