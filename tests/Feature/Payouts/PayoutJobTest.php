<?php

use App\Actions\Payouts\CreateInstructorPayout;
use App\Actions\Payouts\ProcessPayout;
use App\Actions\Payouts\ReconcilePayout;
use App\Enums\LedgerEntryType;
use App\Enums\MockOutcome;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessInstructorPayout;
use App\Jobs\ReconcilePayoutJob;
use App\Models\LedgerEntry;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use Illuminate\Support\Facades\Queue;

it('sends one transfer when the same payout job is dispatched twice', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    ProcessInstructorPayout::dispatchSync($payout->id);
    ProcessInstructorPayout::dispatchSync($payout->id);
    ProcessInstructorPayout::dispatch($payout->id);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and(provider()->payoutCalls)->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout)->count())->toBe(1);

    expectBooksToBalance();
});

it('retries a job that crashed after the provider paid, without paying twice', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    // The worker dies after the provider moved the money, while recording it.
    $crash = true;
    Payout::updating(function (Payout $p) use (&$crash) {
        if ($crash && $p->status === PayoutStatus::Paid) {
            $crash = false;
            throw new RuntimeException('worker killed');
        }
    });

    expect(fn () => ProcessInstructorPayout::dispatchSync($payout->id))->toThrow(RuntimeException::class, 'worker killed');

    $payout->refresh();
    expect($payout->status)->toBe(PayoutStatus::Processing)
        ->and(MockProviderTransfer::query()->sole()->status->value)->toBe('succeeded')
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Payout)->count())->toBe(0);

    // An immediate retry sees the live claim and backs off.
    $job = (new ProcessInstructorPayout($payout->id))->withFakeQueueInteractions();
    $job->handle(app(ProcessPayout::class));
    $job->assertReleased(30);

    // Once the claim expires, the retry asks the provider instead of resending.
    $this->travel(301)->seconds();
    ProcessInstructorPayout::dispatchSync($payout->id);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and(provider()->payoutCalls)->toBe(1)
        ->and(provider()->statusCalls)->toBe(1)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1)
        ->and(balanceOf($instructor)->paid_minor)->toBe(100_000);

    expectBooksToBalance();
});

it('resumes a payout abandoned by a dead worker on the next payouts:process run', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    // Worker claimed the payout and vanished before calling the provider.
    $payout->update(['status' => PayoutStatus::Processing, 'attempts' => 1, 'claimed_until' => now()->addSeconds(300)]);

    $this->artisan('payouts:process');
    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing);

    $this->travel(301)->seconds();
    $this->artisan('payouts:process');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->fresh()->idempotency_key)->toBe("payout:{$instructor->id}:1")
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1);

    expectBooksToBalance();
});

it('queues a reconciliation check when the provider times out', function () {
    Queue::fake([ReconcilePayoutJob::class]);
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess);

    $this->artisan('payouts:process');

    Queue::assertPushed(ReconcilePayoutJob::class, fn ($job) => $job->payoutId === Payout::query()->sole()->id);
});

it('keeps re-checking an unknown payout until the provider can answer', function () {
    Queue::fake([ReconcilePayoutJob::class]);
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess)->statusUnavailable(1);
    $this->artisan('payouts:process');
    $payout = Payout::query()->sole();

    $job = (new ReconcilePayoutJob($payout->id))->withFakeQueueInteractions();
    $job->handle(app(ReconcilePayout::class));
    $job->assertReleased();
    expect($payout->fresh()->status)->toBe(PayoutStatus::Unknown);

    $job = (new ReconcilePayoutJob($payout->id))->withFakeQueueInteractions();
    $job->handle(app(ReconcilePayout::class));
    $job->assertNotReleased();
    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid);
});
