<?php

namespace App\Actions\Payouts;

use App\Contracts\PaymentProvider;
use App\Enums\PayoutAttemptResult;
use App\Models\Payout;
use App\Services\Payouts\PayoutLog;
use App\Services\Payouts\PayoutTransitions;
use Throwable;

/**
 * Calls the provider for a payout the caller has already claimed. No database
 * transaction is open during the call. Any exception, timeout or otherwise,
 * leaves the payout UNKNOWN: we never guess that money did not move.
 */
final class SubmitPayoutToProvider
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PayoutTransitions $transitions,
    ) {}

    public function handle(Payout $claimed, string $source): PayoutAttemptResult
    {
        PayoutLog::info('payout.submitting', $claimed, ['source' => $source]);

        try {
            $result = $this->provider->payout(
                $claimed->idempotency_key,
                $claimed->destination,
                $claimed->amount_minor,
                $claimed->currency,
            );
        } catch (Throwable $e) {
            $this->transitions->markUnknown($claimed->id, class_basename($e).': '.$e->getMessage(), $source);

            return PayoutAttemptResult::Unknown;
        }

        return $this->transitions->applyProviderAnswer(
            $claimed->id,
            $result->status,
            $result->reference,
            $result->failureReason,
            $source,
        );
    }
}
