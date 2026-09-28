<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Services\Ledger\LedgerRecorder;
use App\Services\Payouts\PayoutLog;
use App\Services\Payouts\PayoutTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Drives one payout forward. Safe to call any number of times, from any number
 * of workers, at any point in the payout's life:
 *
 *  - final                        => nothing to do
 *  - claimed by a live worker     => busy, try later
 *  - pending                      => claim it (short transaction), then call the provider
 *  - processing with an expired claim, submitted, unknown
 *                                 => a previous attempt may have reached the provider,
 *                                    so ask the provider (reconcile) instead of resending
 */
final class ProcessPayout
{
    public function __construct(
        private readonly PayoutTransitions $transitions,
        private readonly LedgerRecorder $ledger,
        private readonly SubmitPayoutToProvider $submit,
        private readonly ReconcilePayout $reconcile,
    ) {}

    public function handle(int $payoutId): PayoutAttemptResult
    {
        [$decision, $payout] = DB::transaction(function () use ($payoutId) {
            $payout = $this->transitions->lock($payoutId);

            if ($payout->status->isFinal()) {
                return ['skip', $payout];
            }

            if ($payout->claimed_until?->isFuture()) {
                return ['busy', $payout];
            }

            if ($payout->status !== PayoutStatus::Pending) {
                return ['reconcile', $payout];
            }

            // A refund may have shrunk the balance since this payout was created.
            $balance = $this->ledger->lockBalance($payout->instructor_id);
            if ($payout->amount_minor > $balance->outstanding_minor) {
                return ['cancelled', $this->transitions->cancel($payout->id, 'outstanding balance dropped below payout amount')];
            }

            $payout->update([
                'status' => PayoutStatus::Processing,
                'attempts' => $payout->attempts + 1,
                'claimed_until' => CarbonImmutable::now()->addSeconds((int) config('revenue.payouts.claim_lease_seconds')),
            ]);

            PayoutLog::info('payout.claimed', $payout);

            return ['send', $payout];
        }, attempts: 3);

        return match ($decision) {
            'skip' => PayoutAttemptResult::Skipped,
            'busy' => PayoutAttemptResult::Busy,
            'cancelled' => PayoutAttemptResult::Cancelled,
            'reconcile' => $this->reconcile->handle($payout->id),
            'send' => $this->submit->handle($payout, 'process'),
        };
    }
}
