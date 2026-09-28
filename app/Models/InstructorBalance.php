<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Projection of the ledger, updated in the same transaction as every entry.
 * The ledger is the source of truth; `ledger:verify` rebuilds and compares.
 *
 *   net earned  = earned - reversed + adjusted
 *   position    = net earned - paid
 *   outstanding = max(position, 0)   (we owe the instructor)
 *   recoverable = max(-position, 0)  (instructor owes us, offset against future earnings)
 *
 * The row also serves as the per-instructor mutex: every money movement for an
 * instructor locks it FOR UPDATE first.
 */
class InstructorBalance extends Model
{
    protected $fillable = [
        'instructor_id', 'currency', 'earned_minor', 'reversed_minor', 'adjusted_minor', 'paid_minor',
        'outstanding_minor', 'recoverable_minor', 'payout_sequence', 'version',
    ];

    protected function casts(): array
    {
        return [
            'earned_minor' => 'integer',
            'reversed_minor' => 'integer',
            'adjusted_minor' => 'integer',
            'paid_minor' => 'integer',
            'outstanding_minor' => 'integer',
            'recoverable_minor' => 'integer',
            'payout_sequence' => 'integer',
            'version' => 'integer',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    public function netEarned(): int
    {
        return $this->earned_minor - $this->reversed_minor + $this->adjusted_minor;
    }

    public function position(): int
    {
        return $this->netEarned() - $this->paid_minor;
    }
}
