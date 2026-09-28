<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerVerifier;
use Illuminate\Console\Command;

class LedgerVerifyCommand extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Recompute balances, allocations and payout effects from the ledger and report any drift';

    public function handle(LedgerVerifier $verifier): int
    {
        $violations = $verifier->verify();

        if ($violations === []) {
            $this->info('Ledger verified: projections, allocations, recognition and payouts all reconcile.');

            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        return self::FAILURE;
    }
}
