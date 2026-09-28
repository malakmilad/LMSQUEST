<?php

namespace App\Jobs;

use App\Actions\Payouts\ProcessPayout;
use App\Enums\PayoutAttemptResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Carries only the payout id. Every run re-reads the payout under a lock, so a
 * duplicate dispatch, a retry after a crash, or two workers picking up copies
 * of this job cannot send a second transfer.
 */
class ProcessInstructorPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public function __construct(public readonly int $payoutId) {}

    /** Courtesy only: correctness comes from the row claim in ProcessPayout. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("payout:{$this->payoutId}"))
                ->releaseAfter(30)
                ->expireAfter((int) config('revenue.payouts.claim_lease_seconds') + 60),
        ];
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProcessPayout $process): void
    {
        $result = $process->handle($this->payoutId);

        if ($result === PayoutAttemptResult::Busy) {
            $this->release(30);

            return;
        }

        if ($result->needsReconciliation()) {
            ReconcilePayoutJob::dispatch($this->payoutId)
                ->delay(now()->addSeconds((int) config('revenue.payouts.reconcile_delay_seconds')));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('payouts')->error('payout.job_failed', [
            'payout_id' => $this->payoutId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
