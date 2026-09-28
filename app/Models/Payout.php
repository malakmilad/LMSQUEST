<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Enums\PayoutStatus;
use Database\Factories\PayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    /** @use HasFactory<PayoutFactory> */
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'amount_cents',
        'currency',
        'status',
        'idempotency_key',
        'through_ledger_entry_id',
        'provider',
        'provider_reference',
        'attempt_count',
        'last_error',
        'dispatched_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'attempt_count' => 'integer',
            'status' => PayoutStatus::class,
            'dispatched_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_cents, $this->currency);
    }
}
