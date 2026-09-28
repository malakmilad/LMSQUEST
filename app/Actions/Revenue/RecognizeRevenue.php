<?php

namespace App\Actions\Revenue;

use App\Enums\AllocationStatus;
use App\Enums\LedgerEntryType;
use App\Models\RevenueAllocation;
use App\Services\Ledger\LedgerRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Progressive recognition: an allocation is earned one month-period at a time,
 * and a period counts as earned the moment it starts. Each period posts one
 * earning entry keyed `earning:allocation:{id}:period:{n}`, so running this
 * any number of times, concurrently or late, posts each period exactly once.
 */
final class RecognizeRevenue
{
    public function __construct(private readonly LedgerRecorder $ledger) {}

    /** Recognize every period that has started by $asOf. Returns the number of entries posted. */
    public function handle(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $posted = 0;

        RevenueAllocation::query()
            ->where('status', AllocationStatus::Active)
            ->where('next_recognition_at', '<=', $asOf)
            ->select('id')
            ->chunkById((int) config('revenue.payouts.chunk_size'), function ($allocations) use ($asOf, &$posted) {
                foreach ($allocations as $allocation) {
                    $posted += $this->recognizeAllocation($allocation->id, $asOf);
                }
            });

        return $posted;
    }

    public function recognizeAllocation(int $allocationId, ?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();

        return DB::transaction(function () use ($allocationId, $asOf) {
            $allocation = RevenueAllocation::query()->whereKey($allocationId)->lockForUpdate()->firstOrFail();

            return $this->catchUp($allocation, $asOf);
        }, attempts: 3);
    }

    /** Caller must hold a lock on $allocation inside a transaction. */
    public function catchUp(RevenueAllocation $allocation, CarbonImmutable $asOf): int
    {
        if ($allocation->status !== AllocationStatus::Active) {
            return 0;
        }

        $started = $allocation->periodsStartedBy($asOf);
        $amounts = $allocation->periodAmounts();
        $posted = 0;

        for ($period = $allocation->periods_recognized + 1; $period <= $started; $period++) {
            $amount = $amounts[$period - 1];

            if ($amount === 0) {
                continue;
            }

            $this->ledger->record(
                instructorId: $allocation->instructor_id,
                type: LedgerEntryType::Earning,
                amountMinor: $amount,
                currency: $allocation->currency,
                entryKey: "earning:allocation:{$allocation->id}:period:{$period}",
                references: [
                    'subscription_id' => $allocation->subscription_id,
                    'subscription_payment_id' => $allocation->subscription_payment_id,
                    'revenue_allocation_id' => $allocation->id,
                ],
                metadata: ['period' => $period, 'periods_total' => $allocation->periods_total],
                occurredAt: $allocation->periodStartsAt($period),
            );

            $posted++;
        }

        if ($started > $allocation->periods_recognized) {
            $allocation->update([
                'periods_recognized' => $started,
                'next_recognition_at' => $started < $allocation->periods_total ? $allocation->periodStartsAt($started + 1) : null,
                'status' => $started === $allocation->periods_total ? AllocationStatus::Completed : AllocationStatus::Active,
            ]);
        }

        return $posted;
    }
}
