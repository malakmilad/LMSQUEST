<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'amount_cents' => 7_000,
            'currency' => 'EGP',
            'status' => PayoutStatus::Pending,
            'idempotency_key' => (string) Str::ulid(),
            'through_ledger_entry_id' => null,
            'provider' => 'mock',
            'dispatched_at' => now(),
        ];
    }
}
