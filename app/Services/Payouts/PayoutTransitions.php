<?php

namespace App\Services\Payouts;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Enums\ProviderTransferStatus;
use App\Models\Payout;
use App\Services\Ledger\LedgerRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only code that moves a payout between states. Each transition locks the
 * payout row, re-reads it, and is a no-op if the payout is already final, so
 * two workers reporting the same outcome produce one state change and at most
 * one ledger entry. Lock order is always payout row, then balance row.
 */
final class PayoutTransitions
{
    public function __construct(private readonly LedgerRecorder $ledger) {}

    /** Provider confirmed the money moved. Posts the payout ledger entry exactly once. */
    public function markPaid(int $payoutId, ?string $providerReference, string $source): Payout
    {
        return DB::transaction(function () use ($payoutId, $providerReference, $source) {
            $payout = $this->lock($payoutId);

            if ($payout->status === PayoutStatus::Paid) {
                return $payout;
            }

            if ($payout->status === PayoutStatus::Cancelled) {
                // A cancelled payout was never sent, so its key cannot have succeeded.
                PayoutLog::critical('payout.contradiction', $payout, ['provider_says' => 'succeeded', 'source' => $source]);

                return $payout;
            }

            if ($payout->status === PayoutStatus::Failed) {
                // The provider is the source of truth for whether money moved.
                PayoutLog::critical('payout.failed_then_succeeded', $payout, ['source' => $source]);
            }

            $this->ledger->record(
                instructorId: $payout->instructor_id,
                type: LedgerEntryType::Payout,
                amountMinor: -$payout->amount_minor,
                currency: $payout->currency,
                entryKey: "payout:{$payout->id}",
                references: ['payout_id' => $payout->id],
                metadata: ['provider_reference' => $providerReference, 'idempotency_key' => $payout->idempotency_key],
            );

            $payout->update([
                'status' => PayoutStatus::Paid,
                'provider_reference' => $providerReference ?? $payout->provider_reference,
                'provider_status' => 'succeeded',
                'paid_at' => CarbonImmutable::now(),
                'open_instructor_id' => null,
                'claimed_until' => null,
                'failure_reason' => null,
            ]);

            PayoutLog::info('payout.paid', $payout, ['source' => $source]);

            return $payout;
        }, attempts: 3);
    }

    /** Provider definitively rejected the transfer. No ledger effect; balance stays outstanding. */
    public function markFailed(int $payoutId, ?string $providerReference, ?string $reason, string $source): Payout
    {
        return $this->transition($payoutId, function (Payout $payout) use ($providerReference, $reason, $source) {
            $payout->update([
                'status' => PayoutStatus::Failed,
                'provider_reference' => $providerReference ?? $payout->provider_reference,
                'provider_status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => CarbonImmutable::now(),
                'open_instructor_id' => null,
                'claimed_until' => null,
            ]);

            PayoutLog::warning('payout.failed', $payout, ['reason' => $reason, 'source' => $source]);
        });
    }

    /** Provider accepted the transfer but has not settled it. */
    public function markSubmitted(int $payoutId, ?string $providerReference, string $source): Payout
    {
        return $this->transition($payoutId, function (Payout $payout) use ($providerReference, $source) {
            $payout->update([
                'status' => PayoutStatus::Submitted,
                'provider_reference' => $providerReference ?? $payout->provider_reference,
                'provider_status' => 'pending',
                'submitted_at' => $payout->submitted_at ?? CarbonImmutable::now(),
                'claimed_until' => null,
            ]);

            PayoutLog::info('payout.submitted', $payout, ['source' => $source]);
        });
    }

    /** We cannot tell whether money moved. Never retried blindly; only reconciled. */
    public function markUnknown(int $payoutId, string $reason, string $source): Payout
    {
        return $this->transition($payoutId, function (Payout $payout) use ($reason, $source) {
            $payout->update([
                'status' => PayoutStatus::Unknown,
                'provider_status' => 'unknown',
                'failure_reason' => $reason,
                'claimed_until' => null,
            ]);

            PayoutLog::warning('payout.unknown', $payout, ['reason' => $reason, 'source' => $source]);
        });
    }

    /** Only a payout that was never sent can be cancelled. */
    public function cancel(int $payoutId, string $reason): Payout
    {
        return $this->transition($payoutId, function (Payout $payout) use ($reason) {
            if ($payout->status !== PayoutStatus::Pending) {
                return;
            }

            $payout->update([
                'status' => PayoutStatus::Cancelled,
                'failure_reason' => $reason,
                'open_instructor_id' => null,
                'claimed_until' => null,
            ]);

            PayoutLog::info('payout.cancelled', $payout, ['reason' => $reason]);
        });
    }

    /** Apply what the provider told us. NotFound is the caller's decision, never handled here. */
    public function applyProviderAnswer(
        int $payoutId,
        ProviderTransferStatus $status,
        ?string $providerReference,
        ?string $failureReason,
        string $source,
    ): PayoutAttemptResult {
        return match ($status) {
            ProviderTransferStatus::Succeeded => $this->resultOf($this->markPaid($payoutId, $providerReference, $source)),
            ProviderTransferStatus::Failed => $this->resultOf($this->markFailed($payoutId, $providerReference, $failureReason, $source)),
            ProviderTransferStatus::Pending => $this->resultOf($this->markSubmitted($payoutId, $providerReference, $source)),
            ProviderTransferStatus::Unknown, ProviderTransferStatus::NotFound => $this->resultOf(
                $this->markUnknown($payoutId, "provider answered {$status->value}", $source)
            ),
        };
    }

    public function lock(int $payoutId): Payout
    {
        return Payout::query()->whereKey($payoutId)->lockForUpdate()->firstOrFail();
    }

    private function resultOf(Payout $payout): PayoutAttemptResult
    {
        return match ($payout->status) {
            PayoutStatus::Paid => PayoutAttemptResult::Paid,
            PayoutStatus::Failed => PayoutAttemptResult::Failed,
            PayoutStatus::Submitted => PayoutAttemptResult::Submitted,
            PayoutStatus::Unknown => PayoutAttemptResult::Unknown,
            PayoutStatus::Cancelled => PayoutAttemptResult::Cancelled,
            PayoutStatus::Pending, PayoutStatus::Processing => PayoutAttemptResult::Skipped,
        };
    }

    private function transition(int $payoutId, callable $apply): Payout
    {
        return DB::transaction(function () use ($payoutId, $apply) {
            $payout = $this->lock($payoutId);

            if (! $payout->status->isFinal()) {
                $apply($payout);
            }

            return $payout;
        }, attempts: 3);
    }
}
