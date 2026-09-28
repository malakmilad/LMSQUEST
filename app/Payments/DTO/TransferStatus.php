<?php

namespace App\Payments\DTO;

use App\Enums\ProviderActualStatus;

final readonly class TransferStatus
{
    public function __construct(
        public string $idempotencyKey,
        public string $reference,
        public ProviderActualStatus $actual,
    ) {}
}
