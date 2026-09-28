<?php

namespace App\Models;

use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory;

    protected $fillable = [
        'instructor_id',
        'title',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
