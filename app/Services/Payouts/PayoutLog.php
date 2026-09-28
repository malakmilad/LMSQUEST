<?php

namespace App\Services\Payouts;

use App\Models\Payout;
use Illuminate\Support\Facades\Log;

/** Structured JSON log lines for the payout lifecycle. Never logs the destination account. */
final class PayoutLog
{
    public static function info(string $event, Payout $payout, array $context = []): void
    {
        Log::channel('payouts')->info($event, $payout->logContext() + $context);
    }

    public static function warning(string $event, Payout $payout, array $context = []): void
    {
        Log::channel('payouts')->warning($event, $payout->logContext() + $context);
    }

    public static function critical(string $event, Payout $payout, array $context = []): void
    {
        Log::channel('payouts')->critical($event, $payout->logContext() + $context);
    }
}
