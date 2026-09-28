<?php

namespace App\Actions\Revenue;

use App\Enums\PaymentStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for "the student paid". Idempotent on the payment provider's
 * reference, so a webhook delivered twice records one payment and one allocation.
 */
final class RecordSubscriptionPayment
{
    public function __construct(
        private readonly AllocateSubscriptionRevenue $allocate,
        private readonly RecognizeRevenue $recognize,
    ) {}

    public function handle(
        Subscription $subscription,
        string $providerReference,
        ?int $amountMinor = null,
        ?CarbonImmutable $paidAt = null,
    ): SubscriptionPayment {
        try {
            $payment = DB::transaction(function () use ($subscription, $providerReference, $amountMinor, $paidAt) {
                $existing = SubscriptionPayment::query()->where('provider_reference', $providerReference)->first();
                if ($existing !== null) {
                    return $existing;
                }

                $payment = SubscriptionPayment::query()->create([
                    'subscription_id' => $subscription->id,
                    'amount_minor' => $amountMinor ?? $subscription->amount_minor,
                    'currency' => $subscription->currency,
                    'provider_reference' => $providerReference,
                    'status' => PaymentStatus::Succeeded,
                    'paid_at' => $paidAt ?? CarbonImmutable::now(),
                ]);

                $this->allocate->handle($payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            $payment = SubscriptionPayment::query()->where('provider_reference', $providerReference)->firstOrFail();
        }

        foreach ($payment->allocations()->pluck('id') as $allocationId) {
            $this->recognize->recognizeAllocation($allocationId);
        }

        return $payment->fresh();
    }
}
