<?php

use App\Actions\Payouts\CreateInstructorPayout;
use App\Actions\Payouts\ProcessPayout;
use App\Actions\Payouts\ProcessPayouts;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use Illuminate\Database\UniqueConstraintViolationException;
use Monolog\Formatter\JsonFormatter;

it('pays an instructor exactly once when payouts:process runs twice', function () {
    $instructor = instructorOwed(100_000);

    $this->artisan('payouts:process')->assertSuccessful();
    $this->artisan('payouts:process')->assertSuccessful();

    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->amount_minor)->toBe(100_000)
        ->and($payout->idempotency_key)->toBe("payout:{$instructor->id}:1")
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1)
        ->and(provider()->payoutCalls)->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout)->sole()->amount_minor)->toBe(-100_000);

    $balance = balanceOf($instructor);
    expect($balance->paid_minor)->toBe(100_000)
        ->and($balance->outstanding_minor)->toBe(0);

    expectBooksToBalance();
});

it('pays only the new earnings on the next run after a payout', function () {
    $instructor = instructorOwed(100_000);
    $this->artisan('payouts:process');

    subscribe([$instructor], amountMinor: 50_000);   // +40,000 for the instructor
    $this->artisan('payouts:process');

    expect(Payout::query()->orderBy('id')->pluck('amount_minor')->all())->toBe([100_000, 40_000])
        ->and(Payout::query()->orderBy('id')->pluck('idempotency_key')->all())
        ->toBe(["payout:{$instructor->id}:1", "payout:{$instructor->id}:2"])
        ->and(balanceOf($instructor)->outstanding_minor)->toBe(0);

    expectBooksToBalance();
});

it('does not create a payout below the minimum amount', function () {
    config(['revenue.payouts.min_amount_minor' => 50_000]);
    instructorOwed(40_000);

    $this->artisan('payouts:process');

    expect(Payout::query()->count())->toBe(0);
});

it('skips instructors without a payout account instead of sending money nowhere', function () {
    instructorOwed(100_000, ['payout_account_reference' => null]);

    $this->artisan('payouts:process');

    expect(Payout::query()->count())->toBe(0)->and(provider()->payoutCalls)->toBe(0);
});

it('walks every instructor in chunks without loading them all at once', function () {
    config(['revenue.payouts.chunk_size' => 2]);
    $instructors = collect(range(1, 5))->map(fn () => instructorOwed(100_000));

    $this->artisan('payouts:process');

    expect(Payout::query()->where('status', PayoutStatus::Paid)->count())->toBe(5)
        ->and($instructors->every(fn (Instructor $i) => balanceOf($i)->outstanding_minor === 0))->toBeTrue();
});

it('lets the database refuse a second open payout for the same instructor', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    Payout::query()->create([
        'instructor_id' => $instructor->id,
        'amount_minor' => 100_000,
        'currency' => 'EGP',
        'status' => PayoutStatus::Pending,
        'idempotency_key' => 'payout:sneaky',
        'destination' => 'acct_x',
        'open_instructor_id' => $instructor->id,
    ]);
})->throws(UniqueConstraintViolationException::class);

it('does not create a second payout while one is still open', function () {
    $instructor = instructorOwed(100_000);

    $first = app(CreateInstructorPayout::class)->handle($instructor->id);
    $second = app(CreateInstructorPayout::class)->handle($instructor->id);

    expect($first)->not->toBeNull()->and($second)->toBeNull();
});

it('lets only one worker send a payout when two run at the same moment', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);
    $secondWorker = null;

    // While worker one is inside the provider call, worker two and a whole second batch run.
    provider()->beforeTransfer(function () use ($payout, &$secondWorker) {
        $secondWorker = app(ProcessPayout::class)->handle($payout->id);
        app(ProcessPayouts::class)->handle(sync: true);
    });

    $firstWorker = app(ProcessPayout::class)->handle($payout->id);

    expect($firstWorker)->toBe(PayoutAttemptResult::Paid)
        ->and($secondWorker)->toBe(PayoutAttemptResult::Busy)
        ->and(provider()->payoutCalls)->toBe(1)
        ->and(Payout::query()->count())->toBe(1)
        ->and(balanceOf($instructor)->paid_minor)->toBe(100_000);

    expectBooksToBalance();
});

it('writes structured payout logs without the destination account', function () {
    $log = storage_path('logs/payouts-test.log');
    @unlink($log);
    config(['logging.channels.payouts' => [
        'driver' => 'single',
        'path' => $log,
        'formatter' => JsonFormatter::class,
    ]]);

    $instructor = instructorOwed(100_000, ['payout_account_reference' => 'acct_secret_123456']);
    $this->artisan('payouts:process');

    $lines = collect(file($log))->map(fn ($line) => json_decode($line, true));

    expect($lines->pluck('message')->all())->toContain('payout.created', 'payout.claimed', 'payout.submitting', 'payout.paid')
        ->and($lines->firstWhere('message', 'payout.paid')['context'])->toMatchArray([
            'instructor_id' => $instructor->id,
            'amount_minor' => 100_000,
            'status' => 'paid',
            'idempotency_key' => "payout:{$instructor->id}:1",
        ])
        ->and(file_get_contents($log))->not->toContain('acct_secret_123456');

    @unlink($log);
});
