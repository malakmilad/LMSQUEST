<?php

namespace App\Models;

use App\Enums\RefundType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    protected $fillable = [
        'subscription_payment_id', 'subscription_id', 'type', 'amount_minor', 'currency',
        'periods_refunded', 'reason', 'idempotency_key', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => RefundType::class,
            'amount_minor' => 'integer',
            'periods_refunded' => 'integer',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
