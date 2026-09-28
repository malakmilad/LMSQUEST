<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id', 'plan', 'amount_minor', 'currency', 'starts_at', 'ends_at', 'status', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'status' => SubscriptionStatus::class,
            'amount_minor' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'subscription_course')
            ->withPivot('revenue_share_bps')
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
