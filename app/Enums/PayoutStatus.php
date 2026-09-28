<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Unknown], true);
    }
}
