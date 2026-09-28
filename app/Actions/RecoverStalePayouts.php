<?php

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\Payout;
use Illuminate\Support\Carbon;

final class RecoverStalePayouts
{
    /**
     * Re-dispatch jobs for payouts that have been in pending or processing for
     * longer than $thresholdMinutes without reaching a terminal or unknown state.
     *
     * Safety properties:
     *
     * 1. Idempotency key is unchanged — re-dispatching uses the same key the
     *    provider already saw, so even if the provider did process it the first
     *    time, the call is a no-op and we reconcile via the existing timeout path.
     *
     * 2. ProcessInstructorPayoutJob is ShouldBeUnique (uniqueFor 3600 s).
     *    If the original job is still on the queue, a duplicate dispatch is
     *    silently dropped by the queue driver.
     *
     * 3. ProcessInstructorPayout::handle() acquires a lockForUpdate on the payout
     *    row before doing any work, so two concurrent workers cannot double-pay.
     *
     * @return list<Payout>  Payouts for which a recovery job was dispatched.
     */
    public function handle(int $thresholdMinutes = 30): array
    {
        $cutoff = Carbon::now()->subMinutes($thresholdMinutes);

        $dispatched = [];

        Payout::query()
            ->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Processing])
            // A pending payout without processing_started_at fell off the queue
            // before the worker ever picked it up; use dispatched_at as the age proxy.
            ->where(function ($q) use ($cutoff) {
                $q->whereNotNull('processing_started_at')
                    ->where('processing_started_at', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('processing_started_at')
                            ->where('dispatched_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($payouts) use (&$dispatched) {
                foreach ($payouts as $payout) {
                    ProcessInstructorPayoutJob::dispatch($payout);
                    $dispatched[] = $payout;
                }
            });

        return $dispatched;
    }
}
