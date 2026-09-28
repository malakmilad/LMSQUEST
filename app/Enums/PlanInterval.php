<?php

namespace App\Enums;

enum PlanInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function durationDays(): int
    {
        return match ($this) {
            self::Monthly => 30,
            self::Quarterly => 90,
            self::Annual => 365,
        };
    }
}
