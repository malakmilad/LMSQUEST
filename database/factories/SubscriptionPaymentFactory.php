<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_cents' => 10_000,
            'currency' => 'EGP',
            'instructor_share_bps' => 7000,
            'instructor_pool_cents' => 7_000,
            'platform_fee_cents' => 3_000,
            'status' => PaymentStatus::Succeeded,
            'idempotency_key' => (string) Str::ulid(),
            'paid_at' => now(),
        ];
    }
}
