<?php

namespace App\Models;

use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'course_id',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
