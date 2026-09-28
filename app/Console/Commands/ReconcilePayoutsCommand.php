<?php

namespace App\Console\Commands;

use App\Actions\Payouts\ReconcilePayout;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile';

    protected $description = 'Ask the provider about every payout in unknown/submitted state (or stuck in processing) and apply the answer';

    public function handle(ReconcilePayout $reconcile): int
    {
        $rows = [];

        Payout::query()
            ->where(function ($query) {
                $query->whereIn('status', PayoutStatus::needsReconciliation())
                    ->orWhere(fn ($q) => $q->where('status', PayoutStatus::Processing)->where('claimed_until', '<=', now()));
            })
            ->select(['id', 'status'])
            ->chunkById((int) config('revenue.payouts.chunk_size'), function ($payouts) use ($reconcile, &$rows) {
                foreach ($payouts as $payout) {
                    $rows[] = [$payout->id, $payout->status->value, $reconcile->handle($payout->id)->value];
                }
            });

        if ($rows === []) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->table(['Payout', 'Was', 'Result'], $rows);

        return self::SUCCESS;
    }
}
