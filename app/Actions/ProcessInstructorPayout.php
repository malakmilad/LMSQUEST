<?php

namespace App\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\ProviderActualStatus;
use App\Enums\ProviderReportedStatus;
use App\Models\Instructor;
use App\Models\Payout;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\DTO\TransferRequest;
use Illuminate\Support\Facades\DB;

final class ProcessInstructorPayout
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly LedgerPoster $ledger,
    ) {}

    public function handle(Payout $payout): Payout
    {
        $locked = DB::transaction(function () use ($payout) {
            return Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
        });

        if ($locked->status->isTerminal()) {
            return $locked;
        }

        if ($locked->status === PayoutStatus::Unknown) {
            return $this->reconcile($locked);
        }

        $this->markProcessing($locked);

        $result = $this->provider->transfer(new TransferRequest(
            idempotencyKey: $locked->idempotency_key,
            instructorId: (int) $locked->instructor_id,
            amountCents: (int) $locked->amount_cents,
            currency: $locked->currency,
        ));

        return match ($result->reported) {
            ProviderReportedStatus::Succeeded => $this->markSucceeded($locked, $result->reference),
            ProviderReportedStatus::Failed => $this->markFailed($locked, $result->reference, $result->error),
            ProviderReportedStatus::Timeout => $this->markUnknown($locked, $result->reference, $result->error),
        };
    }

    public function reconcile(Payout $payout): Payout
    {
        $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

        if ($payout->status->isTerminal()) {
            return $payout;
        }

        $status = $this->provider->getTransferStatus($payout->idempotency_key);

        return match ($status->actual) {
            ProviderActualStatus::Succeeded => $this->markSucceeded($payout, $status->reference),
            ProviderActualStatus::Failed => $this->markFailed($payout, $status->reference, 'Reconcile: provider reports failure.'),
        };
    }

    private function markProcessing(Payout $payout): void
    {
        $payout->forceFill([
            'status' => PayoutStatus::Processing,
            'attempt_count' => $payout->attempt_count + 1,
            'processing_started_at' => $payout->processing_started_at ?? now(),
        ])->save();
    }

    private function markSucceeded(Payout $payout, string $reference): Payout
    {
        return DB::transaction(function () use ($payout, $reference) {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($payout->status === PayoutStatus::Succeeded) {
                return $payout;
            }

            $payout->forceFill([
                'status' => PayoutStatus::Succeeded,
                'provider_reference' => $reference,
                'last_error' => null,
                'confirmed_at' => now(),
            ])->save();

            $this->clearInFlight($payout);

            return $payout;
        });
    }

    private function markFailed(Payout $payout, string $reference, ?string $error): Payout
    {
        return DB::transaction(function () use ($payout, $reference, $error) {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($payout->status === PayoutStatus::Succeeded) {
                return $payout;
            }

            if ($payout->status !== PayoutStatus::Failed) {
                $this->ledger->post([
                    'instructor_id' => $payout->instructor_id,
                    'type' => LedgerEntryType::PayoutReversal,
                    'amount_cents' => (int) $payout->amount_cents,
                    'currency' => $payout->currency,
                    'payout_id' => $payout->id,
                    'idempotency_key' => "payout:{$payout->id}:reversal",
                    'description' => "Release hold for failed payout #{$payout->id}",
                ]);
            }

            $payout->forceFill([
                'status' => PayoutStatus::Failed,
                'provider_reference' => $reference,
                'last_error' => $error,
                'confirmed_at' => now(),
            ])->save();

            $this->clearInFlight($payout);

            return $payout;
        });
    }

    private function markUnknown(Payout $payout, string $reference, ?string $error): Payout
    {
        return DB::transaction(function () use ($payout, $reference, $error) {
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

            if ($payout->status->isTerminal()) {
                return $payout;
            }

            $payout->forceFill([
                'status' => PayoutStatus::Unknown,
                'provider_reference' => $reference,
                'last_error' => $error,
            ])->save();

            return $payout;
        });
    }

    private function clearInFlight(Payout $payout): void
    {
        Instructor::query()
            ->whereKey($payout->instructor_id)
            ->where('in_flight_payout_id', $payout->id)
            ->update(['in_flight_payout_id' => null]);
    }
}
