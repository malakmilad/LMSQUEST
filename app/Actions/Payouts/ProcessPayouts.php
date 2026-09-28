<?php

namespace App\Actions\Payouts;

use App\Enums\PayoutStatus;
use App\Jobs\ProcessInstructorPayout;
use App\Models\InstructorBalance;
use App\Models\Payout;
use Carbon\CarbonImmutable;

/**
 * The batch behind `payouts:process`. Safe to run twice, or concurrently:
 * payout creation is serialized per instructor by the balance row lock and the
 * open-payout guard, and the jobs themselves are idempotent.
 */
final class ProcessPayouts
{
    public function __construct(private readonly CreateInstructorPayout $create) {}

    /** @return array{created: int, resumed: int} */
    public function handle(bool $sync = false): array
    {
        $chunk = (int) config('revenue.payouts.chunk_size');
        $minimum = max(1, (int) config('revenue.payouts.min_amount_minor'));
        $created = 0;
        $resumed = 0;

        InstructorBalance::query()
            ->where('outstanding_minor', '>=', $minimum)
            ->select(['id', 'instructor_id'])
            ->chunkById($chunk, function ($balances) use (&$created, $sync) {
                foreach ($balances as $balance) {
                    if ($payout = $this->create->handle($balance->instructor_id)) {
                        $created++;
                        $this->dispatch($payout->id, $sync);
                    }
                }
            });

        // Pick up payouts whose job was lost or whose worker died mid-flight.
        $now = CarbonImmutable::now();
        $staleBefore = $now->subSeconds((int) config('revenue.payouts.redispatch_after_seconds'));

        Payout::query()
            ->where(function ($query) use ($now, $staleBefore) {
                $query->where(fn ($q) => $q->where('status', PayoutStatus::Pending)->where('created_at', '<=', $staleBefore))
                    ->orWhere(fn ($q) => $q->where('status', PayoutStatus::Processing)->where('claimed_until', '<=', $now));
            })
            ->select('id')
            ->chunkById($chunk, function ($payouts) use (&$resumed, $sync) {
                foreach ($payouts as $payout) {
                    $resumed++;
                    $this->dispatch($payout->id, $sync);
                }
            });

        return ['created' => $created, 'resumed' => $resumed];
    }

    private function dispatch(int $payoutId, bool $sync): void
    {
        $sync
            ? ProcessInstructorPayout::dispatchSync($payoutId)
            : ProcessInstructorPayout::dispatch($payoutId);
    }
}
