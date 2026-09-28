<?php

namespace App\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitiateInstructorPayout
{
    public function __construct(private readonly LedgerPoster $ledger) {}

    public function handle(Instructor $instructor, int $minCents = 0): ?Payout
    {
        $payout = DB::transaction(function () use ($instructor, $minCents) {
            $instructor = Instructor::query()
                ->whereKey($instructor->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($instructor->in_flight_payout_id !== null) {
                return null;
            }

            $available = (int) $instructor->available_balance_cents;

            if ($available < $minCents || $available <= 0) {
                return null;
            }

            $throughId = LedgerEntry::query()
                ->where('instructor_id', $instructor->id)
                ->max('id');

            $payout = Payout::query()->create([
                'instructor_id' => $instructor->id,
                'amount_cents' => $available,
                'currency' => $instructor->currency,
                'status' => PayoutStatus::Pending,
                'idempotency_key' => (string) Str::ulid(),
                'through_ledger_entry_id' => $throughId,
                'provider' => 'mock',
                'dispatched_at' => now(),
            ]);

            $this->ledger->post([
                'instructor_id' => $instructor->id,
                'type' => LedgerEntryType::PayoutDebit,
                'amount_cents' => -$available,
                'currency' => $instructor->currency,
                'payout_id' => $payout->id,
                'idempotency_key' => "payout:{$payout->id}:debit",
                'description' => "Hold for payout #{$payout->id}",
            ]);

            $instructor->forceFill(['in_flight_payout_id' => $payout->id])->save();

            return $payout;
        });

        if ($payout === null) {
            return null;
        }

        ProcessInstructorPayoutJob::dispatch($payout);

        return $payout;
    }
}
