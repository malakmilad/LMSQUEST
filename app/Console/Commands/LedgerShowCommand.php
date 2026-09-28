<?php

namespace App\Console\Commands;

use App\Models\Instructor;
use App\Services\Money\Allocator;
use Illuminate\Console\Command;

class LedgerShowCommand extends Command
{
    protected $signature = 'ledger:show {instructor : Instructor id}';

    protected $description = "Print an instructor's ledger and balance";

    public function handle(): int
    {
        $instructor = Instructor::query()->with('balance')->findOrFail($this->argument('instructor'));
        $currency = $instructor->currency;

        $this->info("{$instructor->name} (#{$instructor->id})");

        $this->table(
            ['#', 'When', 'Type', 'Amount', 'Key'],
            $instructor->ledgerEntries()->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($entry) => [
                $entry->id,
                $entry->occurred_at->toDateTimeString(),
                $entry->type->value,
                Allocator::format($entry->amount_minor, $entry->currency),
                $entry->entry_key,
            ]),
        );

        $balance = $instructor->balance;
        if ($balance !== null) {
            $this->table(['Earned', 'Reversed', 'Adjusted', 'Paid', 'Outstanding', 'Recoverable'], [[
                Allocator::format($balance->earned_minor, $currency),
                Allocator::format($balance->reversed_minor, $currency),
                Allocator::format($balance->adjusted_minor, $currency),
                Allocator::format($balance->paid_minor, $currency),
                Allocator::format($balance->outstanding_minor, $currency),
                Allocator::format($balance->recoverable_minor, $currency),
            ]]);
        }

        return self::SUCCESS;
    }
}
