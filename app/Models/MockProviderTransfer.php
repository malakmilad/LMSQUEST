<?php

namespace App\Models;

use App\Enums\ProviderTransferStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The mock provider's own record of a transfer. In production this row lives
 * inside the provider, not in our database.
 */
class MockProviderTransfer extends Model
{
    protected $fillable = [
        'idempotency_key', 'provider_reference', 'destination', 'amount_minor', 'currency', 'status', 'submission_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderTransferStatus::class,
            'amount_minor' => 'integer',
            'submission_count' => 'integer',
        ];
    }
}
