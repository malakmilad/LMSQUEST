<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Instructor extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'payout_account_reference', 'currency'];

    protected $hidden = ['payout_account_reference'];

    protected static function booted(): void
    {
        static::created(function (Instructor $instructor) {
            InstructorBalance::query()->create(['instructor_id' => $instructor->id, 'currency' => $instructor->currency]);
        });
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    public function revenueAllocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }
}
