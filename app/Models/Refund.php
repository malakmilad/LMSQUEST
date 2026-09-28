<?php

namespace App\Models;

use App\Domain\Money\Money;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_payment_id',
        'subscription_id',
        'amount_cents',
        'currency',
        'reason',
        'idempotency_key',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'processed_at' => 'datetime',
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

    public function amount(): Money
    {
        return Money::of((int) $this->amount_cents, $this->currency);
    }
}
