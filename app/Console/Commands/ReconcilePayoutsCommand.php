<?php

namespace App\Console\Commands;

use App\Actions\ReconcileUnknownPayouts;
use Illuminate\Console\Command;

class ReconcilePayoutsCommand extends Command
{
    protected $signature = 'payouts:reconcile';

    protected $description = 'Resolve payouts left in unknown after a provider timeout.';

    public function handle(ReconcileUnknownPayouts $reconcile): int
    {
        $resolved = $reconcile->handle();

        $this->info(sprintf('Reconciled %d unknown payout(s).', count($resolved)));

        return self::SUCCESS;
    }
}
