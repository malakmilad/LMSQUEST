<?php

namespace App\Console\Commands;

use App\Actions\Payouts\ProcessPayouts;
use Illuminate\Console\Command;

class ProcessPayoutsCommand extends Command
{
    protected $signature = 'payouts:process {--sync : Process each payout in this process instead of queueing a job}';

    protected $description = 'Create payouts for instructors with an outstanding balance and queue them for sending';

    public function handle(ProcessPayouts $process): int
    {
        $summary = $process->handle((bool) $this->option('sync'));

        $this->info("Payouts created: {$summary['created']}. Stalled payouts resumed: {$summary['resumed']}.");

        if (! $this->option('sync')) {
            $this->line('Jobs are on the queue; run `php artisan queue:work` to send them.');
        }

        return self::SUCCESS;
    }
}
