<?php

namespace App\Actions\Payouts;

use App\Contracts\PaymentProvider;
use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Enums\ProviderTransferStatus;
use App\Services\Payouts\PayoutLog;
use App\Services\Payouts\PayoutTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves a payout whose outcome we do not know by asking the provider
 * status(idempotency_key) and applying the answer:
 *
 *   succeeded => paid (ledger entry posted once)
 *   failed    => failed (no ledger effect)
 *   pending   => submitted, check again later
 *   unknown   => stays unknown, check again later
 *   not found => the request never arrived; resend with the SAME idempotency key
 *
 * Takes a short claim so concurrent reconcilers do not both resend.
 */
final class ReconcilePayout
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PayoutTransitions $transitions,
        private readonly SubmitPayoutToProvider $submit,
    ) {}

    public function handle(int $payoutId): PayoutAttemptResult
    {
        [$decision, $payout] = DB::transaction(function () use ($payoutId) {
            $payout = $this->transitions->lock($payoutId);

            if ($payout->status->isFinal() || $payout->status === PayoutStatus::Pending) {
                return ['skip', $payout];
            }

            if ($payout->claimed_until?->isFuture()) {
                return ['busy', $payout];
            }

            $payout->update([
                'claimed_until' => $this->leaseEnd(),
                'last_reconciled_at' => CarbonImmutable::now(),
            ]);

            return ['check', $payout];
        }, attempts: 3);

        if ($decision === 'skip') {
            return PayoutAttemptResult::Skipped;
        }

        if ($decision === 'busy') {
            return PayoutAttemptResult::Busy;
        }

        try {
            $answer = $this->provider->status($payout->idempotency_key);
        } catch (Throwable $e) {
            $this->transitions->markUnknown($payout->id, 'status check failed: '.class_basename($e).': '.$e->getMessage(), 'reconcile');

            return PayoutAttemptResult::Unknown;
        }

        PayoutLog::info('payout.reconciling', $payout, ['provider_status' => $answer->status->value]);

        if ($answer->status === ProviderTransferStatus::NotFound) {
            return $this->resend($payout->id);
        }

        return $this->transitions->applyProviderAnswer(
            $payout->id,
            $answer->status,
            $answer->reference,
            $answer->failureReason,
            'reconcile',
        );
    }

    private function resend(int $payoutId): PayoutAttemptResult
    {
        $payout = DB::transaction(function () use ($payoutId) {
            $payout = $this->transitions->lock($payoutId);

            if ($payout->status->isFinal()) {
                return null;
            }

            $payout->update([
                'status' => PayoutStatus::Processing,
                'attempts' => $payout->attempts + 1,
                'claimed_until' => $this->leaseEnd(),
            ]);

            return $payout;
        }, attempts: 3);

        if ($payout === null) {
            return PayoutAttemptResult::Skipped;
        }

        PayoutLog::warning('payout.resending_not_found', $payout);

        return $this->submit->handle($payout, 'reconcile');
    }

    private function leaseEnd(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds((int) config('revenue.payouts.claim_lease_seconds'));
    }
}
