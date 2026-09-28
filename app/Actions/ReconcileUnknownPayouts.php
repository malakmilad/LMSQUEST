<?php

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Jobs\ReconcileUnknownPayoutJob;
use App\Models\Payout;

final class ReconcileUnknownPayouts
{
    /**
     * Chunk all Unknown payouts and dispatch one reconciliation job per payout.
     *
     * Dispatching asynchronously instead of reconciling inline means:
     *  - each payout is retried independently if the provider call fails;
     *  - one slow or erroring provider response cannot interrupt the whole batch;
     *  - the Artisan command returns quickly regardless of how many payouts need
     *    reconciling, keeping scheduled-command slots free.
     *
     * The job is ShouldBeUnique (uniqueFor 3600 s), so re-running the command
     * before the first wave finishes is safe — duplicate dispatches are no-ops.
     *
     * @return list<Payout>  The payouts for which a job was dispatched.
     */
    public function handle(): array
    {
        $dispatched = [];

        Payout::query()
            ->where('status', PayoutStatus::Unknown)
            ->orderBy('id')
            ->chunkById(100, function ($payouts) use (&$dispatched) {
                foreach ($payouts as $payout) {
                    ReconcileUnknownPayoutJob::dispatch($payout);
                    $dispatched[] = $payout;
                }
            });

        return $dispatched;
    }
}
