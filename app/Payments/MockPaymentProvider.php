<?php

namespace App\Payments;

use App\Enums\ProviderActualStatus;
use App\Enums\ProviderReportedStatus;
use App\Models\MockProviderTransfer;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\DTO\TransferRequest;
use App\Payments\DTO\TransferResult;
use App\Payments\DTO\TransferStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * External payout rail stand-in.
 *
 * Randomly succeeds, fails permanently, or times out AFTER the money has
 * already moved. The real result is always persisted first, so a later
 * status check (or an idempotent retry) can discover the truth.
 */
final class MockPaymentProvider implements PaymentProvider
{
    /** @var list<ProviderReportedStatus> */
    private array $scripted = [];

    public function script(ProviderReportedStatus ...$outcomes): void
    {
        $this->scripted = array_values($outcomes);
    }

    public function transfer(TransferRequest $request): TransferResult
    {
        $existing = MockProviderTransfer::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->resultForRetry($existing);
        }

        $reported = $this->nextOutcome();
        $actual = $reported === ProviderReportedStatus::Failed
            ? ProviderActualStatus::Failed
            : ProviderActualStatus::Succeeded;

        $reference = 'mock_'.Str::ulid();

        try {
            $transfer = MockProviderTransfer::query()->create([
                'idempotency_key' => $request->idempotencyKey,
                'instructor_id' => $request->instructorId,
                'amount_cents' => $request->amountCents,
                'currency' => $request->currency,
                'provider_reference' => $reference,
                'actual_status' => $actual,
                'reported_status' => $reported,
            ]);
        } catch (UniqueConstraintViolationException) {
            $transfer = MockProviderTransfer::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->firstOrFail();

            return $this->resultForRetry($transfer);
        }

        return $this->toFirstResult($transfer);
    }

    public function getTransferStatus(string $idempotencyKey): TransferStatus
    {
        $transfer = MockProviderTransfer::query()
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        return new TransferStatus(
            idempotencyKey: $transfer->idempotency_key,
            reference: $transfer->provider_reference,
            actual: $transfer->actual_status,
        );
    }

    private function nextOutcome(): ProviderReportedStatus
    {
        if ($this->scripted !== []) {
            return array_shift($this->scripted);
        }

        if (app()->environment('testing')) {
            throw new RuntimeException(
                'MockPaymentProvider requires a scripted outcome in tests. Call script() first.'
            );
        }

        $weights = config('revenue.mock_provider');
        $roll = random_int(1, $weights['success_weight'] + $weights['permanent_fail_weight'] + $weights['timeout_after_success_weight']);

        if ($roll <= $weights['success_weight']) {
            return ProviderReportedStatus::Succeeded;
        }

        if ($roll <= $weights['success_weight'] + $weights['permanent_fail_weight']) {
            return ProviderReportedStatus::Failed;
        }

        return ProviderReportedStatus::Timeout;
    }

    private function toFirstResult(MockProviderTransfer $transfer): TransferResult
    {
        return match ($transfer->reported_status) {
            ProviderReportedStatus::Succeeded => TransferResult::succeeded($transfer->provider_reference),
            ProviderReportedStatus::Failed => TransferResult::failed($transfer->provider_reference, 'Provider permanently rejected the transfer.'),
            ProviderReportedStatus::Timeout => TransferResult::timeout($transfer->provider_reference),
        };
    }

    /**
     * A retry after a timeout is a new HTTP attempt against a rail that already
     * accepted the money. Return the actual status, not the original timeout.
     */
    private function resultForRetry(MockProviderTransfer $transfer): TransferResult
    {
        return match ($transfer->actual_status) {
            ProviderActualStatus::Succeeded => TransferResult::succeeded($transfer->provider_reference),
            ProviderActualStatus::Failed => TransferResult::failed($transfer->provider_reference, 'Provider permanently rejected the transfer.'),
        };
    }
}
