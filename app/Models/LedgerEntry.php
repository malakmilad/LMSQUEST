<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Only App\Services\Ledger\LedgerRecorder writes these rows,
 * because it also has to update the instructor_balances projection.
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'instructor_id', 'type', 'amount_minor', 'currency', 'entry_key', 'subscription_id',
        'subscription_payment_id', 'revenue_allocation_id', 'refund_id', 'payout_id', 'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw LedgerException::immutable());
        static::deleting(fn () => throw LedgerException::immutable());
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(RevenueAllocation::class, 'revenue_allocation_id');
    }
}
