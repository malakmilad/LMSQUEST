<?php

namespace App\Models;

use App\Domain\Money\Money;
use App\Enums\PlanInterval;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'interval',
        'duration_days',
        'price_cents',
        'currency',
        'instructor_share_bps',
    ];

    protected function casts(): array
    {
        return [
            'interval' => PlanInterval::class,
            'duration_days' => 'integer',
            'price_cents' => 'integer',
            'instructor_share_bps' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function price(): Money
    {
        return Money::of((int) $this->price_cents, $this->currency);
    }
}
