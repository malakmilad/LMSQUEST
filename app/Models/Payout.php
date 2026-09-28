<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payout extends Model
{
    use HasFactory;

    protected $fillable = [
        'instructor_id', 'amount_minor', 'currency', 'status', 'idempotency_key', 'destination',
        'provider_reference', 'provider_status', 'attempts', 'failure_reason', 'open_instructor_id',
        'claimed_until', 'submitted_at', 'paid_at', 'failed_at', 'last_reconciled_at',
    ];

    protected $hidden = ['destination'];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'claimed_until' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function ledgerEntry(): HasOne
    {
        return $this->hasOne(LedgerEntry::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', PayoutStatus::open());
    }

    /** Structured log context. Never includes the destination account. */
    public function logContext(): array
    {
        return [
            'payout_id' => $this->id,
            'instructor_id' => $this->instructor_id,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'idempotency_key' => $this->idempotency_key,
            'provider_reference' => $this->provider_reference,
            'attempts' => $this->attempts,
        ];
    }
}
