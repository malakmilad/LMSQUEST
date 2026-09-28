<?php

namespace App\Console\Commands;

use App\Enums\MockOutcome;
use App\Services\Payments\MockPaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MockProviderForceCommand extends Command
{
    protected $signature = 'mock-provider:force {outcome? : success|permanent_failure|timeout_after_success|timeout_before_receipt|accepted} {--times=1} {--clear}';

    protected $description = 'Force the outcome of the next mock provider transfers (for demos)';

    public function handle(): int
    {
        if ($this->option('clear')) {
            Cache::forget(MockPaymentProvider::FORCED_CACHE_KEY);
            $this->info('Forced outcomes cleared.');

            return self::SUCCESS;
        }

        $outcome = MockOutcome::tryFrom((string) $this->argument('outcome'));
        if ($outcome === null) {
            $this->error('Outcome must be one of: '.implode(', ', array_column(MockOutcome::cases(), 'value')));

            return self::INVALID;
        }

        MockPaymentProvider::force($outcome, max(1, (int) $this->option('times')));
        $this->info("Next {$this->option('times')} new transfer(s) will be: {$outcome->value}.");

        return self::SUCCESS;
    }
}
