<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Student;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return $this->planAttributes(SubscriptionPlan::Monthly, CarbonImmutable::now()) + [
            'student_id' => Student::factory(),
            'currency' => 'EGP',
            'status' => SubscriptionStatus::Active,
        ];
    }

    public function plan(SubscriptionPlan $plan, ?CarbonImmutable $startsAt = null): static
    {
        return $this->state(fn (array $attributes) => $this->planAttributes(
            $plan,
            $startsAt ?? CarbonImmutable::parse($attributes['starts_at']),
        ));
    }

    public function monthly(?CarbonImmutable $startsAt = null): static
    {
        return $this->plan(SubscriptionPlan::Monthly, $startsAt);
    }

    public function threeMonth(?CarbonImmutable $startsAt = null): static
    {
        return $this->plan(SubscriptionPlan::ThreeMonth, $startsAt);
    }

    public function annual(?CarbonImmutable $startsAt = null): static
    {
        return $this->plan(SubscriptionPlan::Annual, $startsAt);
    }

    public function price(int $amountMinor): static
    {
        return $this->state(['amount_minor' => $amountMinor]);
    }

    private function planAttributes(SubscriptionPlan $plan, CarbonImmutable $startsAt): array
    {
        return [
            'plan' => $plan,
            'amount_minor' => $plan->priceMinor(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMonthsNoOverflow($plan->months()),
        ];
    }
}
