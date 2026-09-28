<?php

namespace App\Console\Commands;

use App\Actions\RecoverStalePayouts;
use Illuminate\Console\Command;

class RecoverStalePayoutsCommand extends Command
{
    protected $signature = 'payouts:recover-stale
                            {--threshold=30 : Minutes a payout may stay in pending/processing before it is considered stale}';

    protected $description = 'Re-dispatch jobs for pending/processing payouts that appear to have stalled.';

    public function handle(RecoverStalePayouts $action): int
    {
        $threshold = (int) $this->option('threshold');

        $dispatched = $action->handle($threshold);

        $this->info(sprintf(
            'Dispatched recovery for %d stale payout(s) (threshold: %d minutes).',
            count($dispatched),
            $threshold,
        ));

        return self::SUCCESS;
    }
}
