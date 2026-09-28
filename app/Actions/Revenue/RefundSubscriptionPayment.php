<?php

namespace App\Actions\Revenue;

use App\Actions\Payouts\CancelPendingPayout;
use App\Enums\AllocationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\RefundType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\RevenueException;
use App\Models\Refund;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\Ledger\LedgerRecorder;
use App\Services\Money\Allocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Refunds a payment without touching existing ledger rows.
 *
 *  Full:     the student gets everything back. Every earning already recognized
 *            for this payment is reversed with a refund_reversal entry, and the
 *            remaining periods are cancelled. If the instructor was already paid,
 *            their balance goes negative: that is the recoverable amount, offset
 *            against future earnings.
 *  Prorated: the student gets back the periods that have not started. Started
 *            periods stay earned (nothing to reverse); future periods are cancelled
 *            and so never reach the ledger.
 *
 * One refund per payment (UNIQUE on refunds.subscription_payment_id); calling
 * this again returns the existing refund.
 */
final class RefundSubscriptionPayment
{
    public function __construct(
        private readonly LedgerRecorder $ledger,
        private readonly RecognizeRevenue $recognize,
        private readonly CancelPendingPayout $cancelPending,
    ) {}

    public function handle(SubscriptionPayment $payment, RefundType $type, ?string $reason = null, ?CarbonImmutable $at = null): Refund
    {
        $at ??= CarbonImmutable::now();

        [$refund, $instructorIds] = DB::transaction(function () use ($payment, $type, $reason, $at) {
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($existing = $payment->refund()->first()) {
                return [$existing, []];
            }

            if ($payment->status !== PaymentStatus::Succeeded) {
                throw RevenueException::paymentNotRefundable($payment->id, $payment->status->value);
            }

            $subscription = Subscription::query()->whereKey($payment->subscription_id)->lockForUpdate()->firstOrFail();
            $allocations = RevenueAllocation::query()
                ->where('subscription_payment_id', $payment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Bring recognition up to the refund date so "earned" is exact at this instant.
            foreach ($allocations as $allocation) {
                $this->recognize->catchUp($allocation, $at);
            }

            $periods = $subscription->plan->months();
            $started = RevenueAllocation::countStartedPeriods($subscription->starts_at, $periods, $at);

            if ($type === RefundType::Prorated && $started >= $periods) {
                throw RevenueException::nothingLeftToRefund($payment->id);
            }

            $amount = $type === RefundType::Full
                ? $payment->amount_minor
                : $this->unstartedGross($payment, $allocations->all(), $periods, $started);

            $refund = Refund::query()->create([
                'subscription_payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'type' => $type,
                'amount_minor' => $amount,
                'currency' => $payment->currency,
                'periods_refunded' => $type === RefundType::Full ? $periods : $periods - $started,
                'reason' => $reason,
                'idempotency_key' => "refund:payment:{$payment->id}",
                'refunded_at' => $at,
            ]);

            foreach ($allocations as $allocation) {
                $recognized = $allocation->recognizedAmount();

                if ($type === RefundType::Full && $recognized > 0) {
                    $this->ledger->record(
                        instructorId: $allocation->instructor_id,
                        type: LedgerEntryType::RefundReversal,
                        amountMinor: -$recognized,
                        currency: $allocation->currency,
                        entryKey: "refund_reversal:refund:{$refund->id}:allocation:{$allocation->id}",
                        references: [
                            'subscription_id' => $subscription->id,
                            'subscription_payment_id' => $payment->id,
                            'revenue_allocation_id' => $allocation->id,
                            'refund_id' => $refund->id,
                        ],
                        metadata: ['refund_type' => $type->value, 'periods_reversed' => $allocation->periods_recognized],
                        occurredAt: $at,
                    );
                }

                $allocation->update(['status' => AllocationStatus::Cancelled, 'next_recognition_at' => null]);
            }

            $payment->update([
                'status' => $amount === $payment->amount_minor ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
            ]);

            $subscription->update([
                'status' => $type === RefundType::Full ? SubscriptionStatus::Refunded : SubscriptionStatus::Cancelled,
                'cancelled_at' => $at,
            ]);

            return [$refund, $allocations->pluck('instructor_id')->unique()->all()];
        }, attempts: 3);

        foreach ($instructorIds as $instructorId) {
            $this->cancelPending->ifExceedsOutstanding($instructorId);
        }

        return $refund;
    }

    /**
     * Gross value (platform + instructors) of the periods that have not started.
     * Each party's per-period amounts come from the same deterministic split used
     * for recognition, so earned + refunded always equals the payment exactly.
     *
     * @param  list<RevenueAllocation>  $allocations
     */
    private function unstartedGross(SubscriptionPayment $payment, array $allocations, int $periods, int $started): int
    {
        $total = array_sum(array_slice(Allocator::evenly($payment->platform_share_minor, $periods), $started));

        foreach ($allocations as $allocation) {
            $total += array_sum(array_slice($allocation->periodAmounts(), $started));
        }

        return $total;
    }
}
