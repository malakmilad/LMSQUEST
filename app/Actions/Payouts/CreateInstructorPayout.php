<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\Payout;
use App\Services\Ledger\LedgerRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates the payout for an instructor's current outstanding balance, or
 * returns null when there is nothing to pay or a payout is already open.
 *
 * Under the balance row lock: at most one open payout per instructor (also a
 * UNIQUE index on open_instructor_id), and the idempotency key comes from a
 * per-instructor sequence, so it is fixed at creation and reused on every retry.
 */
final class CreateInstructorPayout
{
    public function __construct(private readonly LedgerRecorder $ledger) {}

    public function handle(int $instructorId): ?Payout
    {
        try {
            return DB::transaction(function () use ($instructorId) {
                $balance = $this->ledger->lockBalance($instructorId);

                if (Payout::query()->where('instructor_id', $instructorId)->open()->exists()) {
                    return null;
                }

                $amount = $balance->outstanding_minor;
                if ($amount <= 0 || $amount < (int) config('revenue.payouts.min_amount_minor')) {
                    return null;
                }

                $destination = Instructor::query()->whereKey($instructorId)->value('payout_account_reference');
                if (blank($destination)) {
                    Log::channel('payouts')->warning('payout.skipped_no_destination', ['instructor_id' => $instructorId]);

                    return null;
                }

                $balance->increment('payout_sequence');

                $payout = Payout::query()->create([
                    'instructor_id' => $instructorId,
                    'amount_minor' => $amount,
                    'currency' => $balance->currency,
                    'status' => PayoutStatus::Pending,
                    'idempotency_key' => "payout:{$instructorId}:{$balance->payout_sequence}",
                    'destination' => $destination,
                    'open_instructor_id' => $instructorId,
                ]);

                Log::channel('payouts')->info('payout.created', $payout->logContext());

                return $payout;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
