<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Exceptions\ImmutableLedgerException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'subscription_payment_id',
        'instructor_id',
        'weight',
        'gross_cents',
        'platform_fee_cents',
        'net_cents',
        'currency',
        'idempotency_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'gross_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'net_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new ImmutableLedgerException('Revenue allocations are immutable.');
        });

        static::deleting(function (): never {
            throw new ImmutableLedgerException('Revenue allocations are immutable.');
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function net(): Money
    {
        return Money::of((int) $this->net_cents, $this->currency);
    }
}
