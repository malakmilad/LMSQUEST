<?php

use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerException;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Services\Ledger\LedgerRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function post(Instructor $instructor, LedgerEntryType $type, int $amount, string $key): LedgerEntry
{
    return DB::transaction(fn () => app(LedgerRecorder::class)->record($instructor->id, $type, $amount, 'EGP', $key));
}

it('refuses to edit a ledger entry', function () {
    subscribe([Instructor::factory()->create()]);

    LedgerEntry::query()->first()->update(['amount_minor' => 1]);
})->throws(LedgerException::class, 'append-only');

it('refuses to delete a ledger entry', function () {
    subscribe([Instructor::factory()->create()]);

    LedgerEntry::query()->first()->delete();
})->throws(LedgerException::class, 'append-only');

it('records the same business event once even if it is posted twice', function () {
    $instructor = Instructor::factory()->create();

    $first = post($instructor, LedgerEntryType::Adjustment, 5_000, 'adjustment:goodwill:1');
    $second = post($instructor, LedgerEntryType::Adjustment, 5_000, 'adjustment:goodwill:1');

    expect($second->id)->toBe($first->id)
        ->and(balanceOf($instructor)->adjusted_minor)->toBe(5_000)
        ->and(balanceOf($instructor)->version)->toBe(1);
});

it('rejects a replay of an entry key with a different amount', function () {
    $instructor = Instructor::factory()->create();
    post($instructor, LedgerEntryType::Adjustment, 5_000, 'adjustment:goodwill:1');

    post($instructor, LedgerEntryType::Adjustment, 6_000, 'adjustment:goodwill:1');
})->throws(LedgerException::class, 'different type or amount');

it('enforces the sign of each entry type', function (LedgerEntryType $type, int $amount) {
    post(Instructor::factory()->create(), $type, $amount, 'bad:'.$type->value);
})->throws(LedgerException::class)->with([
    'positive payout' => [LedgerEntryType::Payout, 100],
    'positive reversal' => [LedgerEntryType::RefundReversal, 100],
    'negative earning' => [LedgerEntryType::Earning, -100],
    'zero adjustment' => [LedgerEntryType::Adjustment, 0],
]);

it('keeps outstanding and recoverable mutually exclusive', function () {
    $instructor = Instructor::factory()->create();

    post($instructor, LedgerEntryType::Adjustment, -20_000, 'adjustment:clawback');
    expect(balanceOf($instructor))->outstanding_minor->toBe(0)->recoverable_minor->toBe(20_000);

    post($instructor, LedgerEntryType::Adjustment, 50_000, 'adjustment:bonus');
    expect(balanceOf($instructor))->outstanding_minor->toBe(30_000)->recoverable_minor->toBe(0);
});

it('passes ledger:verify on consistent books and fails when the projection is tampered with', function () {
    $instructor = instructorOwed(100_000);
    $this->artisan('payouts:process');

    $this->artisan('ledger:verify')->assertSuccessful();

    InstructorBalance::query()->where('instructor_id', $instructor->id)->update(['outstanding_minor' => 999]);

    $this->artisan('ledger:verify')
        ->expectsOutputToContain('outstanding_minor is 999, ledger says 0')
        ->assertFailed();
});

it('shows an instructor ledger on the command line', function () {
    $instructor = instructorOwed(100_000);

    $this->artisan('ledger:show', ['instructor' => $instructor->id])
        ->expectsOutputToContain('EGP 1,000.00')
        ->assertSuccessful();
});

it('lets the database reject a wrongly signed ledger row even if application checks are bypassed', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('CHECK constraints are installed on MySQL only.');
    }

    $instructor = Instructor::factory()->create();

    DB::table('ledger_entries')->insert([
        'instructor_id' => $instructor->id,
        'type' => 'payout',
        'amount_minor' => 100,
        'currency' => 'EGP',
        'entry_key' => 'raw:insert',
        'occurred_at' => now(),
    ]);
})->throws(QueryException::class);
