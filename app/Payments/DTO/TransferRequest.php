<?php

namespace App\Payments\DTO;

final readonly class TransferRequest
{
    public function __construct(
        public string $idempotencyKey,
        public int $instructorId,
        public int $amountCents,
        public string $currency,
    ) {}
}
