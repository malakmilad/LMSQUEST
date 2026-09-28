<?php

namespace App\Payments\Contracts;

use App\Payments\DTO\TransferRequest;
use App\Payments\DTO\TransferResult;
use App\Payments\DTO\TransferStatus;

interface PaymentProvider
{
    /**
     * Move money to an instructor. Must be idempotent on $request->idempotencyKey.
     */
    public function transfer(TransferRequest $request): TransferResult;

    /**
     * Discover the actual outcome after a timeout / unknown state.
     */
    public function getTransferStatus(string $idempotencyKey): TransferStatus;
}
