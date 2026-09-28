<?php

namespace App\Actions;

use App\Domain\Money\Money;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class RecordSubscriptionPayment
{
    public function __construct(private readonly AllocateSubscriptionRevenue $allocator) {}

    public function handle(Subscription $subscription, string $idempotencyKey): SubscriptionPayment
    {
        $subscription->loadMissing('plan');

        $existing = SubscriptionPayment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $price = $subscription->plan->price();
        $shareBps = (int) $subscription->plan->instructor_share_bps;
        $pool = $price->shareBps($shareBps);
        $platform = $price->subtract($pool);

        try {
            $payment = DB::transaction(function () use ($subscription, $idempotencyKey, $price, $shareBps, $pool, $platform) {
                $payment = SubscriptionPayment::query()->create([
                    'subscription_id' => $subscription->id,
                    'amount_cents' => $price->cents,
                    'currency' => $price->currency,
                    'instructor_share_bps' => $shareBps,
                    'instructor_pool_cents' => $pool->cents,
                    'platform_fee_cents' => $platform->cents,
                    'status' => PaymentStatus::Succeeded,
                    'idempotency_key' => $idempotencyKey,
                    'paid_at' => now(),
                ]);

                if ($subscription->status !== SubscriptionStatus::Active) {
                    $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
                }

                $this->allocator->handle($payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            return SubscriptionPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }

        return $payment;
    }
}
