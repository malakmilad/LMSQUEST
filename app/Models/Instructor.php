<?php

namespace App\Models;

use App\Domain\Money\Money;
use Database\Factories\InstructorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Instructor extends Model
{
    /** @use HasFactory<InstructorFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'available_balance_cents',
        'currency',
        'in_flight_payout_id',
    ];

    protected function casts(): array
    {
        return [
            'available_balance_cents' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function inFlightPayout(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'in_flight_payout_id');
    }

    public function availableBalance(): Money
    {
        return Money::of((int) $this->available_balance_cents, $this->currency);
    }

    public function hasInFlightPayout(): bool
    {
        if ($this->in_flight_payout_id === null) {
            return false;
        }

        return $this->inFlightPayout?->status->isInFlight() ?? false;
    }
}
