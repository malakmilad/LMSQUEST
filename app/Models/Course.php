<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Course extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'instructor_id'];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(Subscription::class, 'subscription_course')
            ->withPivot('revenue_share_bps')
            ->withTimestamps();
    }
}
