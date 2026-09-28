<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Earning = 'earning';
    case RefundReversal = 'refund_reversal';
    case Payout = 'payout';
    case Adjustment = 'adjustment';

    public function acceptsAmount(int $amountMinor): bool
    {
        return match ($this) {
            self::Earning => $amountMinor > 0,
            self::RefundReversal, self::Payout => $amountMinor < 0,
            self::Adjustment => $amountMinor !== 0,
        };
    }
}
