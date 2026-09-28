<?php

namespace App\Services\Payments;

use App\Enums\ProviderTransferStatus;

/** The provider's synchronous answer to a payout request. */
final readonly class ProviderResult
{
    public function __construct(
        public ProviderTransferStatus $status,
        public ?string $reference = null,
        public ?string $failureReason = null,
    ) {}
}
