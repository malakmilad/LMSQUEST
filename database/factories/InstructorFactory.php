<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instructor>
 */
class InstructorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->instructor(),
            'available_balance_cents' => 0,
            'currency' => 'EGP',
            'in_flight_payout_id' => null,
        ];
    }
}
