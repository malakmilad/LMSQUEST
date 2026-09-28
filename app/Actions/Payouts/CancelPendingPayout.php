<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Services\Ledger\LedgerRecorder;
use App\Services\Payouts\PayoutTransitions;
use Illuminate\Support\Facades\DB;

/**
 * After a refund shrinks a balance, a payout that has not been sent yet may
 * now be too large. Cancel it; the next payouts:process run creates a new one
 * for the correct amount. Payouts already handed to a worker are left alone:
 * if they pay out, the excess becomes a recoverable balance.
 */
final class CancelPendingPayout
{
    public function __construct(
        private readonly PayoutTransitions $transitions,
        private readonly LedgerRecorder $ledger,
    ) {}

    public function ifExceedsOutstanding(int $instructorId): ?Payout
    {
        $payoutId = Payout::query()
            ->where('instructor_id', $instructorId)
            ->where('status', PayoutStatus::Pending)
            ->value('id');

        if ($payoutId === null) {
            return null;
        }

        return DB::transaction(function () use ($payoutId, $instructorId) {
            $payout = $this->transitions->lock($payoutId);

            if ($payout->status !== PayoutStatus::Pending || $payout->claimed_until?->isFuture()) {
                return null;
            }

            $balance = $this->ledger->lockBalance($instructorId);

            if ($payout->amount_minor <= $balance->outstanding_minor) {
                return null;
            }

            return $this->transitions->cancel($payout->id, 'outstanding balance dropped below payout amount');
        }, attempts: 3);
    }
}
