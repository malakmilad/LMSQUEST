<?php

namespace App\Payments\DTO;

use App\Enums\ProviderReportedStatus;

final readonly class TransferResult
{
    public function __construct(
        public ProviderReportedStatus $reported,
        public string $reference,
        public ?string $error = null,
    ) {}

    public static function succeeded(string $reference): self
    {
        return new self(ProviderReportedStatus::Succeeded, $reference);
    }

    public static function failed(string $reference, string $error): self
    {
        return new self(ProviderReportedStatus::Failed, $reference, $error);
    }

    public static function timeout(string $reference): self
    {
        return new self(ProviderReportedStatus::Timeout, $reference, 'Provider timed out after accepting the request.');
    }
}
