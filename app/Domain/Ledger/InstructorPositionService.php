<?php

namespace App\Domain\Ledger;

use App\Domain\Money\Money;
use App\Enums\LedgerEntryType;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Payout;

final class InstructorPositionService
{
    public function for(Instructor $instructor): InstructorPosition
    {
        $currency = $instructor->currency;

        $sums = LedgerEntry::query()
            ->where('instructor_id', $instructor->id)
            ->selectRaw('type, SUM(amount_cents) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $earned = (int) ($sums[LedgerEntryType::InstructorEarning->value] ?? 0);
        $clawback = abs((int) ($sums[LedgerEntryType::RefundClawback->value] ?? 0));
        $payoutDebit = abs((int) ($sums[LedgerEntryType::PayoutDebit->value] ?? 0));
        $payoutReversal = (int) ($sums[LedgerEntryType::PayoutReversal->value] ?? 0);

        $inFlight = (int) Payout::query()
            ->where('instructor_id', $instructor->id)
            ->whereIn('status', ['pending', 'processing', 'unknown'])
            ->sum('amount_cents');

        $paid = $payoutDebit - $payoutReversal - $inFlight;
        $ledgerBalance = (int) LedgerEntry::query()
            ->where('instructor_id', $instructor->id)
            ->sum('amount_cents');

        return new InstructorPosition(
            lifetimeEarned: Money::of($earned, $currency),
            lifetimeClawedBack: Money::of($clawback, $currency),
            paid: Money::of(max(0, $paid), $currency),
            inFlight: Money::of($inFlight, $currency),
            available: Money::of((int) $instructor->fresh()->available_balance_cents, $currency),
            ledgerBalance: Money::of($ledgerBalance, $currency),
        );
    }
}
