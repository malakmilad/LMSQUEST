<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Enums\MockOutcome;
use App\Enums\ProviderTransferStatus;
use App\Models\MockProviderTransfer;
use Closure;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Deterministic stand-in for a payout provider. It keeps its own transfer
 * records (mock_provider_transfers) and honours idempotency keys the way a real
 * provider does: a replayed key returns the original transfer and never moves money twice.
 *
 * Outcome of a new transfer, first match wins:
 *   1. outcomes queued by tests with willReturn()
 *   2. outcomes forced from the CLI (`php artisan mock-provider:force`)
 *   3. magic destination prefixes (config payments.mock.magic_destinations)
 *   4. payments.mock.mode (success | permanent_failure | timeout_after_success | random)
 */
class MockPaymentProvider implements PaymentProvider
{
    public const FORCED_CACHE_KEY = 'mock-provider:forced-outcomes';

    /** @var list<MockOutcome> */
    private array $outcomes = [];

    private int $statusUnavailableFor = 0;

    private ?Closure $beforeTransfer = null;

    public int $payoutCalls = 0;

    public int $statusCalls = 0;

    public function willReturn(MockOutcome ...$outcomes): static
    {
        array_push($this->outcomes, ...$outcomes);

        return $this;
    }

    /** The next $times status() calls cannot say what happened (status endpoint degraded). */
    public function statusUnavailable(int $times = 1): static
    {
        $this->statusUnavailableFor += $times;

        return $this;
    }

    /** Run $hook once, just before the next transfer is processed (used to interleave workers in tests). */
    public function beforeTransfer(?Closure $hook): static
    {
        $this->beforeTransfer = $hook;

        return $this;
    }

    public static function force(MockOutcome $outcome, int $times = 1): void
    {
        $queue = Cache::get(self::FORCED_CACHE_KEY, []);
        Cache::forever(self::FORCED_CACHE_KEY, [...$queue, ...array_fill(0, $times, $outcome->value)]);
    }

    public function payout(string $idempotencyKey, string $destination, int $amountMinor, string $currency): ProviderResult
    {
        $this->payoutCalls++;

        if ($this->beforeTransfer !== null) {
            $hook = $this->beforeTransfer;
            $this->beforeTransfer = null;
            $hook($idempotencyKey);
        }

        $existing = MockProviderTransfer::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $existing->increment('submission_count');

            if ($existing->amount_minor !== $amountMinor || $existing->destination !== $destination) {
                return new ProviderResult(ProviderTransferStatus::Failed, null, 'idempotency_key_reused_with_different_parameters');
            }

            return new ProviderResult($existing->status, $existing->provider_reference, $this->failureReasonFor($existing->status));
        }

        $outcome = $this->resolveOutcome($destination);
        $reference = 'mock_tr_'.substr(hash('sha256', $idempotencyKey), 0, 20);

        $store = fn (ProviderTransferStatus $status) => MockProviderTransfer::query()->create([
            'idempotency_key' => $idempotencyKey,
            'provider_reference' => $reference,
            'destination' => $destination,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => $status,
        ]);

        switch ($outcome) {
            case MockOutcome::Success:
                $store(ProviderTransferStatus::Succeeded);

                return new ProviderResult(ProviderTransferStatus::Succeeded, $reference);

            case MockOutcome::PermanentFailure:
                $store(ProviderTransferStatus::Failed);

                return new ProviderResult(ProviderTransferStatus::Failed, $reference, $this->failureReasonFor(ProviderTransferStatus::Failed));

            case MockOutcome::Accepted:
                $store(ProviderTransferStatus::Pending);

                return new ProviderResult(ProviderTransferStatus::Pending, $reference);

            case MockOutcome::TimeoutAfterSuccess:
                $store(ProviderTransferStatus::Succeeded);

                throw new ProviderTimeoutException('Mock provider: response lost after the transfer succeeded.');
            case MockOutcome::TimeoutBeforeReceipt:
                throw new ProviderTimeoutException('Mock provider: request never reached the provider.');
        }
    }

    public function status(string $idempotencyKey): ProviderStatus
    {
        $this->statusCalls++;

        if ($this->statusUnavailableFor > 0) {
            $this->statusUnavailableFor--;

            return new ProviderStatus(ProviderTransferStatus::Unknown);
        }

        $transfer = MockProviderTransfer::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($transfer === null) {
            return new ProviderStatus(ProviderTransferStatus::NotFound);
        }

        // Accepted transfers settle the first time anyone asks about them.
        if ($transfer->status === ProviderTransferStatus::Pending) {
            $transfer->update(['status' => ProviderTransferStatus::Succeeded]);
        }

        return new ProviderStatus($transfer->status, $transfer->provider_reference, $this->failureReasonFor($transfer->status));
    }

    private function resolveOutcome(string $destination): MockOutcome
    {
        if ($this->outcomes !== []) {
            return array_shift($this->outcomes);
        }

        $forced = Cache::get(self::FORCED_CACHE_KEY, []);
        if ($forced !== []) {
            $next = array_shift($forced);
            Cache::forever(self::FORCED_CACHE_KEY, $forced);

            return MockOutcome::from($next);
        }

        foreach (config('payments.mock.magic_destinations', []) as $prefix => $outcome) {
            if (str_starts_with($destination, $prefix)) {
                return MockOutcome::from($outcome);
            }
        }

        $mode = config('payments.mock.mode', 'success');

        if ($mode !== 'random') {
            return MockOutcome::from($mode);
        }

        if (app()->runningUnitTests()) {
            throw new LogicException('Random mock outcomes are not allowed in tests. Script the outcome with willReturn().');
        }

        return $this->randomOutcome();
    }

    private function randomOutcome(): MockOutcome
    {
        $weights = config('payments.mock.random_weights');
        $roll = random_int(1, array_sum($weights));

        foreach ($weights as $outcome => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return MockOutcome::from($outcome);
            }
        }

        return MockOutcome::Success;
    }

    private function failureReasonFor(ProviderTransferStatus $status): ?string
    {
        return $status === ProviderTransferStatus::Failed ? 'destination_account_invalid' : null;
    }
}
