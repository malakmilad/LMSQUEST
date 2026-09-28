<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Monthly = 'monthly';
    case ThreeMonth = 'three_month';
    case Annual = 'annual';

    public function months(): int
    {
        return (int) config("revenue.plans.{$this->value}.months");
    }

    public function priceMinor(): int
    {
        return (int) config("revenue.plans.{$this->value}.price_minor");
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::ThreeMonth => '3 months',
            self::Annual => 'Annual',
        };
    }
}
