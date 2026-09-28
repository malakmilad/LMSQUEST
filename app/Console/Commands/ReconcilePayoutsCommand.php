<?php

namespace App\Console\Commands;

use App\Actions\ReconcileUnknownPayouts;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile';

    protected $description = 'Dispatch reconciliation jobs for payouts left in unknown after a provider timeout.';

    public function handle(ReconcileUnknownPayouts $reconcile): int
    {
        $dispatched = $reconcile->handle();

        $this->info(sprintf('Dispatched reconciliation for %d unknown payout(s).', count($dispatched)));

        return self::SUCCESS;
    }
}
