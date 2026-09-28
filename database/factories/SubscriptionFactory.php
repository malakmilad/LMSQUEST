<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = now();

        return [
            'user_id' => User::factory()->student(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDays(30),
            'cancelled_at' => null,
        ];
    }
}
