<?php

namespace App\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Money\Money;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InsufficientRefundableAmountException;
use App\Models\Refund;
use App\Models\SubscriptionPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProcessSubscriptionRefund
{
    public function __construct(private readonly LedgerPoster $ledger) {}

    public function handle(
        SubscriptionPayment $payment,
        int $amountCents,
        string $idempotencyKey,
        ?string $reason = null,
    ): Refund {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Refund amount must be positive.');
        }

        $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($payment, $amountCents, $idempotencyKey, $reason) {
                $payment = SubscriptionPayment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $refundable = $payment->refundableCents();

                if ($amountCents > $refundable) {
                    throw new InsufficientRefundableAmountException(
                        "Cannot refund {$amountCents} cents; only {$refundable} remains."
                    );
                }

                $refund = Refund::query()->create([
                    'subscription_payment_id' => $payment->id,
                    'subscription_id' => $payment->subscription_id,
                    'amount_cents' => $amountCents,
                    'currency' => $payment->currency,
                    'reason' => $reason,
                    'idempotency_key' => $idempotencyKey,
                    'processed_at' => now(),
                ]);

                $this->clawbackInstructors($payment, $refund, $amountCents);
                $this->updatePaymentStatus($payment);

                return $refund;
            });
        } catch (UniqueConstraintViolationException) {
            return Refund::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }
    }

    private function clawbackInstructors(SubscriptionPayment $payment, Refund $refund, int $amountCents): void
    {
        $allocations = $payment->allocations()->orderBy('instructor_id')->get();

        if ($allocations->isEmpty()) {
            return;
        }

        $originalPool = (int) $payment->instructor_pool_cents;
        $originalPayment = (int) $payment->amount_cents;

        // Cumulative approach: compute the total clawback that *should* have been
        // posted across all refunds including this one, then subtract what has
        // already been posted. This ensures rounding errors do not accumulate
        // across multiple partial refunds — the final refund always zeroes the
        // pool regardless of how many partial refunds preceded it.
        $totalRefundedCents = $payment->refundedCents(); // includes the current refund (saved before this call)
        $targetCumulativePool = intdiv($originalPool * $totalRefundedCents, $originalPayment);

        $priorCumulativePool = (int) abs(
            (int) $payment->ledgerEntries()
                ->where('type', LedgerEntryType::RefundClawback->value)
                ->sum('amount_cents')
        );

        $clawbackPool = $targetCumulativePool - $priorCumulativePool;

        if ($clawbackPool <= 0) {
            return;
        }

        $weights = $allocations->mapWithKeys(
            fn ($allocation) => [(int) $allocation->instructor_id => (int) $allocation->weight]
        )->all();

        $shares = Money::of($clawbackPool, $payment->currency)->splitByWeights($weights);

        foreach ($shares as $instructorId => $share) {
            if ($share->isZero()) {
                continue;
            }

            $this->ledger->post([
                'instructor_id' => $instructorId,
                'type' => LedgerEntryType::RefundClawback,
                'amount_cents' => -$share->cents,
                'currency' => $payment->currency,
                'subscription_payment_id' => $payment->id,
                'refund_id' => $refund->id,
                'idempotency_key' => "clawback:refund:{$refund->id}:instructor:{$instructorId}",
                'description' => "Clawback for refund #{$refund->id} on payment #{$payment->id}",
            ]);
        }
    }

    private function updatePaymentStatus(SubscriptionPayment $payment): void
    {
        $refunded = $payment->refundedCents();
        $payment->status = $refunded >= (int) $payment->amount_cents
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
        $payment->save();

        if ($payment->status === PaymentStatus::Refunded) {
            $payment->subscription->forceFill([
                'status' => SubscriptionStatus::Refunded,
                'cancelled_at' => $payment->subscription->cancelled_at ?? now(),
            ])->save();
        }
    }
}
