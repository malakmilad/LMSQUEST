<?php

use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Real concurrency: several OS processes run `payouts:process --sync` against
 * the same MySQL database at the same time. Row locks, the open-payout UNIQUE
 * guard and claim leases are what keep money correct here, not test hooks.
 *
 * Needs MySQL (SQLite serializes writers, so it cannot show the race):
 *   docker compose up -d
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=lms_testing DB_USERNAME=lms DB_PASSWORD=secret php vendor/bin/pest --testsuite=Integration
 */
beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Multi-process concurrency test runs against MySQL only.');
    }
});

it('pays every instructor exactly once when four payouts:process runs overlap', function () {
    $instructors = collect(range(1, 40))->map(fn () => instructorOwed(100_000));

    $processes = collect(range(1, 4))->map(function () {
        $process = new Process([PHP_BINARY, 'artisan', 'payouts:process', '--sync'], base_path(), null, null, 120);
        $process->start();

        return $process;
    });

    $processes->each->wait();
    $processes->each(fn (Process $p) => expect($p->getExitCode())->toBe(0, $p->getErrorOutput()));

    $createdPerProcess = $processes->map(fn (Process $p) => (int) (preg_match('/Payouts created: (\d+)/', $p->getOutput(), $m) ? $m[1] : -1));
    expect($createdPerProcess->sum())->toBe(40)
        ->and($createdPerProcess->filter()->count())->toBeGreaterThan(1, 'Processes did not overlap: '.$createdPerProcess->implode(','));

    expect(Payout::query()->count())->toBe(40)
        ->and(Payout::query()->where('status', PayoutStatus::Paid)->count())->toBe(40)
        ->and(MockProviderTransfer::query()->count())->toBe(40)
        ->and(MockProviderTransfer::query()->max('submission_count'))->toBe(1)
        ->and($instructors->every(fn (Instructor $i) => balanceOf($i)->outstanding_minor === 0))->toBeTrue();

    expectBooksToBalance();
});
