<?php

namespace App\Models;

use App\Enums\AllocationStatus;
use App\Services\Money\Allocator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One instructor's share of one payment, recognized one month-period at a time.
 */
class RevenueAllocation extends Model
{
    protected $fillable = [
        'subscription_payment_id', 'subscription_id', 'instructor_id', 'share_bps', 'amount_minor', 'currency',
        'periods_total', 'periods_recognized', 'recognition_starts_at', 'next_recognition_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AllocationStatus::class,
            'share_bps' => 'integer',
            'amount_minor' => 'integer',
            'periods_total' => 'integer',
            'periods_recognized' => 'integer',
            'recognition_starts_at' => 'immutable_datetime',
            'next_recognition_at' => 'immutable_datetime',
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

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return list<int> Amount earned in each period, summing exactly to amount_minor. */
    public function periodAmounts(): array
    {
        return Allocator::evenly($this->amount_minor, $this->periods_total);
    }

    /** Period n (1-based) starts n-1 months after recognition starts. */
    public function periodStartsAt(int $period): CarbonImmutable
    {
        return $this->recognition_starts_at->addMonthsNoOverflow($period - 1);
    }

    /** How many periods have started by $at (0..periods_total). */
    public function periodsStartedBy(CarbonImmutable $at): int
    {
        return self::countStartedPeriods($this->recognition_starts_at, $this->periods_total, $at);
    }

    public static function countStartedPeriods(CarbonImmutable $startsAt, int $periods, CarbonImmutable $at): int
    {
        $started = 0;

        for ($period = 1; $period <= $periods; $period++) {
            if ($startsAt->addMonthsNoOverflow($period - 1)->lessThanOrEqualTo($at)) {
                $started = $period;
            }
        }

        return $started;
    }

    public function recognizedAmount(): int
    {
        return array_sum(array_slice($this->periodAmounts(), 0, $this->periods_recognized));
    }
}
