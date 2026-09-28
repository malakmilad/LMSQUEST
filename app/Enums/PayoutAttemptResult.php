<?php

namespace App\Enums;

/** What one processing / reconciliation attempt achieved. */
enum PayoutAttemptResult: string
{
    case Paid = 'paid';
    case Failed = 'failed';
    case Submitted = 'submitted';
    case Unknown = 'unknown';
    case Cancelled = 'cancelled';

    /** Another worker holds a live claim on the payout. */
    case Busy = 'busy';

    /** Nothing to do (already final, or not in a state this step handles). */
    case Skipped = 'skipped';

    public function needsReconciliation(): bool
    {
        return $this === self::Unknown || $this === self::Submitted;
    }
}
