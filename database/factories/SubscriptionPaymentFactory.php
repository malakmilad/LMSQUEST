<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Raw payment rows (not allocated). To record a payment the way production
 * does, with allocation and recognition, use RecordSubscriptionPayment.
 *
 * @extends Factory<SubscriptionPayment>
 */
class SubscriptionPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount_minor' => fn (array $attributes) => Subscription::query()->find($attributes['subscription_id'])?->amount_minor ?? 30_000,
            'currency' => 'EGP',
            'provider_reference' => 'pay_'.Str::ulid(),
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentStatus::Failed, 'paid_at' => null]);
    }

    public function pending(): static
    {
        return $this->state(['status' => PaymentStatus::Pending, 'paid_at' => null]);
    }
}
