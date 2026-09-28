<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Enums\PaymentStatus;
use Database\Factories\SubscriptionPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPayment extends Model
{
    /** @use HasFactory<SubscriptionPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'amount_cents',
        'currency',
        'instructor_share_bps',
        'instructor_pool_cents',
        'platform_fee_cents',
        'status',
        'idempotency_key',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'instructor_share_bps' => 'integer',
            'instructor_pool_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function amount(): Money
    {
        return Money::of((int) $this->amount_cents, $this->currency);
    }

    public function refundedCents(): int
    {
        return (int) $this->refunds()->sum('amount_cents');
    }

    public function refundableCents(): int
    {
        return (int) $this->amount_cents - $this->refundedCents();
    }
}
