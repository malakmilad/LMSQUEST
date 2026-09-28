<?php

namespace App\Enums;

enum PayoutStatus: string
{
    /** Created, amount reserved by the open-payout guard, never sent. */
    case Pending = 'pending';

    /** Claimed by a worker that is (or was) talking to the provider. */
    case Processing = 'processing';

    /** Provider accepted the transfer but has not settled it yet. */
    case Submitted = 'submitted';

    /** We do not know whether money moved. Must be reconciled, never retried blindly. */
    case Unknown = 'unknown';

    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Processing, self::Submitted, self::Unknown];
    }

    /** @return list<self> */
    public static function needsReconciliation(): array
    {
        return [self::Submitted, self::Unknown];
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
            self::Pending, self::Processing, self::Submitted => 'info',
            self::Cancelled => 'gray',
        };
    }
}
