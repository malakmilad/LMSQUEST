<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case InstructorEarning = 'instructor_earning';
    case PlatformFee = 'platform_fee';
    case RefundClawback = 'refund_clawback';
    case PayoutDebit = 'payout_debit';
    case PayoutReversal = 'payout_reversal';
}
