<?php

namespace Database\Factories;

use App\Models\Instructor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Instructor> */
class InstructorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'payout_account_reference' => 'acct_'.Str::lower(Str::random(12)),
            'currency' => 'EGP',
        ];
    }

    public function withoutPayoutAccount(): static
    {
        return $this->state(['payout_account_reference' => null]);
    }

    /** Destination whose transfers the mock provider always rejects. */
    public function withRejectedAccount(): static
    {
        return $this->state(fn () => ['payout_account_reference' => 'acct_fail_'.Str::lower(Str::random(8))]);
    }

    /** Destination whose transfers succeed but whose responses always time out. */
    public function withTimingOutAccount(): static
    {
        return $this->state(fn () => ['payout_account_reference' => 'acct_timeout_'.Str::lower(Str::random(8))]);
    }
}
