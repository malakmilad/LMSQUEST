<?php

namespace App\Jobs;

use App\Actions\Payouts\ReconcilePayout;
use App\Enums\PayoutAttemptResult;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps asking the provider about an uncertain payout until it is paid or
 * failed. If the job gives up, the scheduled `payouts:reconcile` sweep still
 * covers the payout; it is never forgotten and never blindly resent.
 */
class ReconcilePayoutJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;

    public function __construct(public readonly int $payoutId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(ReconcilePayout $reconcile): void
    {
        $result = $reconcile->handle($this->payoutId);

        if ($result === PayoutAttemptResult::Busy || $result->needsReconciliation()) {
            $this->release(max(60, (int) config('revenue.payouts.reconcile_delay_seconds') * min($this->attempts(), 10)));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('payouts')->critical('payout.reconcile_gave_up', [
            'payout_id' => $this->payoutId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
