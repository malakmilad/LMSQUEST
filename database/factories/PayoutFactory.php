<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\Payout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Raw payout rows for UI and query tests. Payouts that affect money must go
 * through CreateInstructorPayout / ProcessPayout so the ledger stays consistent.
 *
 * @extends Factory<Payout>
 */
class PayoutFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => Instructor::factory(),
            'amount_minor' => 50_000,
            'currency' => 'EGP',
            'status' => PayoutStatus::Failed,
            'idempotency_key' => 'payout:factory:'.Str::ulid(),
            'destination' => 'acct_'.Str::lower(Str::random(12)),
            'attempts' => 1,
            'failure_reason' => 'destination_account_invalid',
            'failed_at' => now(),
        ];
    }

    public function status(PayoutStatus $status): static
    {
        return $this->state([
            'status' => $status,
            'open_instructor_id' => fn (array $attributes) => $status->isFinal() ? null : $attributes['instructor_id'],
        ]);
    }
}
