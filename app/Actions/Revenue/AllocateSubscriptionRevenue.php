<?php

namespace App\Actions\Revenue;

use App\Enums\AllocationStatus;
use App\Exceptions\RevenueException;
use App\Models\Course;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use App\Services\Money\Allocator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Splits a succeeded payment into the platform share and one allocation per
 * instructor. Allocation is deterministic and happens once per payment
 * (guarded by allocated_at under a row lock and UNIQUE(payment, instructor)).
 *
 * Instructor weights:
 *  - every course on the subscription has revenue_share_bps  => those shares (must sum to 10000),
 *    summed per instructor;
 *  - no course has one                                        => equal share per course,
 *    so an instructor with two of the four courses gets half the pool;
 *  - some but not all                                         => rejected (ambiguous);
 *  - no courses at all                                        => the platform keeps the payment.
 */
final class AllocateSubscriptionRevenue
{
    /** @return Collection<int, RevenueAllocation> */
    public function handle(SubscriptionPayment $payment): Collection
    {
        return DB::transaction(function () use ($payment) {
            $payment = SubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->allocated_at !== null) {
                return $payment->allocations()->orderBy('id')->get();
            }

            $subscription = $payment->subscription;
            $courses = $subscription->courses()->with('instructor')->orderBy('courses.id')->get();

            $platformBps = (int) config('revenue.platform_percentage') * 100;
            $platformShare = $courses->isEmpty() ? $payment->amount_minor : Allocator::percentage($payment->amount_minor, $platformBps);
            $pool = $payment->amount_minor - $platformShare;

            $allocations = collect();

            if ($courses->isNotEmpty()) {
                $weights = $this->instructorWeights($subscription->id, $courses);
                $amounts = Allocator::allocate($pool, $weights);
                $shareBps = Allocator::allocate(10_000, $weights);
                $instructors = $courses->pluck('instructor')->keyBy('id');

                foreach ($amounts as $instructorId => $amount) {
                    if ($amount === 0) {
                        continue;
                    }

                    $instructor = $instructors[$instructorId];
                    if ($instructor->currency !== $payment->currency) {
                        throw RevenueException::currencyMismatch($instructorId, $instructor->currency, $payment->currency);
                    }

                    $allocations->push(RevenueAllocation::query()->create([
                        'subscription_payment_id' => $payment->id,
                        'subscription_id' => $subscription->id,
                        'instructor_id' => $instructorId,
                        'share_bps' => $shareBps[$instructorId],
                        'amount_minor' => $amount,
                        'currency' => $payment->currency,
                        'periods_total' => $subscription->plan->months(),
                        'periods_recognized' => 0,
                        'recognition_starts_at' => $subscription->starts_at,
                        'next_recognition_at' => $subscription->starts_at,
                        'status' => AllocationStatus::Active,
                    ]));
                }
            }

            $payment->update([
                'platform_share_bps' => $courses->isEmpty() ? 10_000 : $platformBps,
                'platform_share_minor' => $platformShare,
                'instructor_pool_minor' => $pool,
                'allocated_at' => now(),
            ]);

            return $allocations;
        });
    }

    /**
     * @param  Collection<int, Course>  $courses
     * @return array<int, int> instructor id => weight, ordered by instructor id
     */
    private function instructorWeights(int $subscriptionId, Collection $courses): array
    {
        $withShare = $courses->filter(fn ($course) => $course->pivot->revenue_share_bps !== null);

        if ($withShare->isNotEmpty() && $withShare->count() !== $courses->count()) {
            throw RevenueException::mixedShares($subscriptionId);
        }

        $weights = [];

        if ($withShare->isNotEmpty()) {
            $sum = (int) $courses->sum(fn ($course) => $course->pivot->revenue_share_bps);
            if ($sum !== 10_000) {
                throw RevenueException::sharesDoNotSumTo100($subscriptionId, $sum);
            }

            foreach ($courses as $course) {
                $weights[$course->instructor_id] = ($weights[$course->instructor_id] ?? 0) + (int) $course->pivot->revenue_share_bps;
            }
        } else {
            foreach ($courses as $course) {
                $weights[$course->instructor_id] = ($weights[$course->instructor_id] ?? 0) + 1;
            }
        }

        ksort($weights);

        return $weights;
    }
}
