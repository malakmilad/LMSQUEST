<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Enums\LedgerEntryType;
use App\Exceptions\ImmutableLedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'instructor_id',
        'type',
        'amount_cents',
        'currency',
        'subscription_payment_id',
        'refund_id',
        'payout_id',
        'idempotency_key',
        'description',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new ImmutableLedgerException('Ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new ImmutableLedgerException('Ledger entries are immutable.');
        });
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_cents, $this->currency);
    }
}
