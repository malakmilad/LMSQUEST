<?php

namespace App\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Money\Money;
use App\Enums\LedgerEntryType;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Illuminate\Database\UniqueConstraintViolationException;

final class AllocateSubscriptionRevenue
{
    public function __construct(private readonly LedgerPoster $ledger) {}

    public function handle(SubscriptionPayment $payment): void
    {
        $payment->loadMissing('subscription.enrollments.course');

        $weights = $this->weights($payment);

        if ($weights === []) {
            $this->postPlatformFee($payment, (int) $payment->amount_cents);

            return;
        }

        $gross = Money::of((int) $payment->amount_cents, $payment->currency);
        $pool = $gross->shareBps((int) $payment->instructor_share_bps);
        $shares = $pool->splitByWeights($weights);

        $totalNet = 0;

        foreach ($shares as $instructorId => $share) {
            $this->recordAllocation($payment, (int) $instructorId, $weights[$instructorId], $share);
            $totalNet += $share->cents;
        }

        $this->postPlatformFee($payment, (int) $payment->amount_cents - $totalNet);
    }

    /**
     * @return array<int, int> instructor_id => enrolled course count
     */
    private function weights(SubscriptionPayment $payment): array
    {
        $weights = [];

        foreach ($payment->subscription->enrollments as $enrollment) {
            $instructorId = (int) $enrollment->course->instructor_id;
            $weights[$instructorId] = ($weights[$instructorId] ?? 0) + 1;
        }

        ksort($weights);

        return $weights;
    }

    private function recordAllocation(
        SubscriptionPayment $payment,
        int $instructorId,
        int $weight,
        Money $net,
    ): void {
        $key = "alloc:payment:{$payment->id}:instructor:{$instructorId}";

        try {
            RevenueAllocation::query()->create([
                'subscription_payment_id' => $payment->id,
                'instructor_id' => $instructorId,
                'weight' => $weight,
                'gross_cents' => $payment->amount_cents,
                'platform_fee_cents' => (int) $payment->amount_cents - (int) $payment->instructor_pool_cents,
                'net_cents' => $net->cents,
                'currency' => $payment->currency,
                'idempotency_key' => $key,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Snapshot already recorded.
        }

        if ($net->isZero()) {
            return;
        }

        $this->ledger->post([
            'instructor_id' => $instructorId,
            'type' => LedgerEntryType::InstructorEarning,
            'amount_cents' => $net->cents,
            'currency' => $payment->currency,
            'subscription_payment_id' => $payment->id,
            'idempotency_key' => "earn:payment:{$payment->id}:instructor:{$instructorId}",
            'description' => "Instructor share of subscription payment #{$payment->id}",
        ]);
    }

    private function postPlatformFee(SubscriptionPayment $payment, int $feeCents): void
    {
        if ($feeCents === 0) {
            return;
        }

        $this->ledger->post([
            'instructor_id' => null,
            'type' => LedgerEntryType::PlatformFee,
            'amount_cents' => $feeCents,
            'currency' => $payment->currency,
            'subscription_payment_id' => $payment->id,
            'idempotency_key' => "fee:payment:{$payment->id}",
            'description' => "Platform cut of subscription payment #{$payment->id}",
        ]);
    }
}
