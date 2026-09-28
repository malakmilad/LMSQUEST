<?php

namespace App\Console\Commands;

use App\Actions\DispatchInstructorPayouts;
use Illuminate\Console\Command;

class DispatchPayoutsCommand extends Command
{
    protected $signature = 'payouts:dispatch
                            {--min= : Minimum available balance in cents (defaults to config)}
                            {--limit=500 : Maximum instructors to consider this run}';

    protected $description = 'Pay instructors what they are currently owed.';

    public function handle(DispatchInstructorPayouts $dispatch): int
    {
        $min = $this->option('min');
        $minCents = $min === null || $min === ''
            ? (int) config('revenue.min_payout_cents')
            : (int) $min;

        $created = $dispatch->handle(
            minCents: $minCents,
            limit: (int) $this->option('limit'),
        );

        $this->info(sprintf('Dispatched %d instructor payout(s) (min %d cents).', count($created), $minCents));

        return self::SUCCESS;
    }
}
