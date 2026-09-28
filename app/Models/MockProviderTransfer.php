<?php

namespace App\Models;

use App\Enums\ProviderActualStatus;
use App\Enums\ProviderReportedStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MockProviderTransfer extends Model
{
    protected $fillable = [
        'idempotency_key',
        'instructor_id',
        'amount_cents',
        'currency',
        'provider_reference',
        'actual_status',
        'reported_status',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'actual_status' => ProviderActualStatus::class,
            'reported_status' => ProviderReportedStatus::class,
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }
}
