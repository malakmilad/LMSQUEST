<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id', 'amount_minor', 'currency', 'provider_reference', 'status',
        'platform_share_bps', 'platform_share_minor', 'instructor_pool_minor', 'paid_at', 'allocated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'platform_share_bps' => 'integer',
            'platform_share_minor' => 'integer',
            'instructor_pool_minor' => 'integer',
            'paid_at' => 'immutable_datetime',
            'allocated_at' => 'immutable_datetime',
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

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
