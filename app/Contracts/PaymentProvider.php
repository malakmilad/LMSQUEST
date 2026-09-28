<?php

namespace App\Contracts;

use App\Services\Payments\ProviderResult;
use App\Services\Payments\ProviderStatus;
use App\Services\Payments\ProviderTimeoutException;

interface PaymentProvider
{
    /**
     * Send money to an instructor. The provider must treat a repeated
     * $idempotencyKey as the same transfer and never move money twice.
     *
     * @throws ProviderTimeoutException when we cannot tell whether the transfer happened.
     *                                  Callers must treat this, and any other exception, as UNKNOWN.
     */
    public function payout(string $idempotencyKey, string $destination, int $amountMinor, string $currency): ProviderResult;

    /** Ask the provider what actually happened to the transfer with this key. */
    public function status(string $idempotencyKey): ProviderStatus;
}
