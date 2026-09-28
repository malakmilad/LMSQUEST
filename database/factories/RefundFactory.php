<?php

namespace Database\Factories;

use App\Models\Refund;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_payment_id' => SubscriptionPayment::factory(),
            'subscription_id' => Subscription::factory(),
            'amount_cents' => 5_000,
            'currency' => 'EGP',
            'reason' => 'mid-term cancellation',
            'idempotency_key' => (string) Str::ulid(),
            'processed_at' => now(),
        ];
    }
}
