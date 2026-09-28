<?php

namespace App\Services\Payments;

use App\Enums\ProviderTransferStatus;

/** The provider's answer to "what happened to transfer X?". */
final readonly class ProviderStatus
{
    public function __construct(
        public ProviderTransferStatus $status,
        public ?string $reference = null,
        public ?string $failureReason = null,
    ) {}
}
