<?php

namespace Database\Factories;

use App\Enums\PlanInterval;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Monthly',
            'interval' => PlanInterval::Monthly,
            'duration_days' => PlanInterval::Monthly->durationDays(),
            'price_cents' => 29_900,
            'currency' => 'EGP',
            'instructor_share_bps' => 7000,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'name' => 'Monthly',
            'interval' => PlanInterval::Monthly,
            'duration_days' => 30,
            'price_cents' => 29_900,
        ]);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => [
            'name' => 'Quarterly',
            'interval' => PlanInterval::Quarterly,
            'duration_days' => 90,
            'price_cents' => 79_900,
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn () => [
            'name' => 'Annual',
            'interval' => PlanInterval::Annual,
            'duration_days' => 365,
            'price_cents' => 299_900,
        ]);
    }
}
