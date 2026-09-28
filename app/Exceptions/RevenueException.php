<?php

namespace App\Exceptions;

use RuntimeException;

class RevenueException extends RuntimeException
{
    public static function mixedShares(int $subscriptionId): self
    {
        return new self("Subscription {$subscriptionId} sets revenue_share_bps on some courses but not all.");
    }

    public static function sharesDoNotSumTo100(int $subscriptionId, int $sumBps): self
    {
        return new self("Subscription {$subscriptionId} course shares sum to {$sumBps} bps; they must sum to 10000.");
    }

    public static function currencyMismatch(int $instructorId, string $expected, string $actual): self
    {
        return new self("Instructor {$instructorId} is paid in {$expected}; payment is in {$actual}.");
    }

    public static function paymentNotRefundable(int $paymentId, string $status): self
    {
        return new self("Payment {$paymentId} is {$status} and cannot be refunded.");
    }

    public static function nothingLeftToRefund(int $paymentId): self
    {
        return new self("Every period of payment {$paymentId} has started; a prorated refund would be zero. Use a full refund.");
    }
}
