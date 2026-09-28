<?php

namespace App\Services\Ledger;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every derived number from the ledger and reports any drift.
 * An empty result means the books balance.
 */
final class LedgerVerifier
{
    /** @return list<string> */
    public function verify(): array
    {
        return [
            ...$this->projectionMatchesLedger(),
            ...$this->allocationsSumToPayments(),
            ...$this->recognitionMatchesAllocations(),
            ...$this->payoutsHaveExactlyTheirLedgerEffect(),
        ];
    }

    /** @return list<string> */
    private function projectionMatchesLedger(): array
    {
        $violations = [];

        $sums = LedgerEntry::query()
            ->select('instructor_id', 'type', DB::raw('SUM(amount_minor) as total'))
            ->groupBy('instructor_id', 'type')
            ->get()
            ->groupBy('instructor_id');

        $balances = InstructorBalance::query()->get()->keyBy('instructor_id');

        foreach ($sums->keys()->merge($balances->keys())->unique() as $instructorId) {
            $byType = ($sums[$instructorId] ?? collect())->mapWithKeys(fn ($row) => [$row->type->value => (int) $row->total]);

            $earned = $byType[LedgerEntryType::Earning->value] ?? 0;
            $reversed = -($byType[LedgerEntryType::RefundReversal->value] ?? 0);
            $adjusted = $byType[LedgerEntryType::Adjustment->value] ?? 0;
            $paid = -($byType[LedgerEntryType::Payout->value] ?? 0);
            $position = $earned - $reversed + $adjusted - $paid;

            $expected = [
                'earned_minor' => $earned,
                'reversed_minor' => $reversed,
                'adjusted_minor' => $adjusted,
                'paid_minor' => $paid,
                'outstanding_minor' => max($position, 0),
                'recoverable_minor' => max(-$position, 0),
            ];

            $balance = $balances[$instructorId] ?? null;

            foreach ($expected as $column => $value) {
                $actual = $balance?->{$column} ?? 0;
                if ($actual !== $value) {
                    $violations[] = "Instructor {$instructorId}: {$column} is {$actual}, ledger says {$value}.";
                }
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function allocationsSumToPayments(): array
    {
        $violations = [];

        SubscriptionPayment::query()
            ->whereNotNull('allocated_at')
            ->withSum('allocations', 'amount_minor')
            ->chunkById(500, function ($payments) use (&$violations) {
                foreach ($payments as $payment) {
                    $instructors = (int) $payment->allocations_sum_amount_minor;

                    if ($payment->platform_share_minor + $payment->instructor_pool_minor !== $payment->amount_minor) {
                        $violations[] = "Payment {$payment->id}: platform + pool != amount.";
                    }

                    if ($instructors !== $payment->instructor_pool_minor) {
                        $violations[] = "Payment {$payment->id}: allocations sum to {$instructors}, pool is {$payment->instructor_pool_minor}.";
                    }
                }
            });

        return $violations;
    }

    /** @return list<string> */
    private function recognitionMatchesAllocations(): array
    {
        $violations = [];

        RevenueAllocation::query()
            ->withSum(['ledgerEntries as earned_sum' => fn ($q) => $q->where('type', LedgerEntryType::Earning)], 'amount_minor')
            ->chunkById(500, function ($allocations) use (&$violations) {
                foreach ($allocations as $allocation) {
                    $earned = (int) $allocation->earned_sum;
                    if ($earned !== $allocation->recognizedAmount()) {
                        $violations[] = "Allocation {$allocation->id}: {$earned} earned in ledger, {$allocation->recognizedAmount()} recognized.";
                    }
                }
            });

        return $violations;
    }

    /** @return list<string> */
    private function payoutsHaveExactlyTheirLedgerEffect(): array
    {
        $violations = [];

        Payout::query()
            ->withCount('ledgerEntry as ledger_count')
            ->withSum('ledgerEntry as ledger_sum', 'amount_minor')
            ->chunkById(500, function ($payouts) use (&$violations) {
                foreach ($payouts as $payout) {
                    $count = (int) $payout->ledger_count;
                    $sum = (int) $payout->ledger_sum;

                    if ($payout->status === PayoutStatus::Paid && ($count !== 1 || $sum !== -$payout->amount_minor)) {
                        $violations[] = "Payout {$payout->id} is paid but has {$count} ledger entries totalling {$sum}.";
                    }

                    if ($payout->status !== PayoutStatus::Paid && $count !== 0) {
                        $violations[] = "Payout {$payout->id} is {$payout->status->value} but has a ledger effect.";
                    }
                }
            });

        return $violations;
    }
}
