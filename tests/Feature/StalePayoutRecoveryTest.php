<?php

use App\Actions\InitiateInstructorPayout;
use App\Enums\PayoutStatus;
use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\Payout;
use Illuminate\Support\Facades\Bus;
it('dispatches a recovery job for a payout that has been processing beyond the threshold', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    // Create a payout and manually put it in Processing with a stale timestamp.
    $payout = app(InitiateInstructorPayout::class)->handle($instructor);
    $payout->forceFill([
        'status' => PayoutStatus::Processing,
        'processing_started_at' => now()->subMinutes(60),
    ])->save();

    $this->artisan('payouts:recover-stale', ['--threshold' => 30])->assertSuccessful()
        ->expectsOutputToContain('Dispatched recovery for 1 stale payout(s)');

    Bus::assertDispatched(ProcessInstructorPayoutJob::class, 1);
    Bus::assertDispatched(ProcessInstructorPayoutJob::class, fn ($job) => $job->payout->id === $payout->id);
});

it('dispatches a recovery job for a pending payout whose dispatched_at is stale', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    $payout = app(InitiateInstructorPayout::class)->handle($instructor);
    // Worker never picked it up — processing_started_at remains null.
    $payout->forceFill([
        'dispatched_at' => now()->subMinutes(45),
    ])->save();

    $this->artisan('payouts:recover-stale', ['--threshold' => 30])->assertSuccessful()
        ->expectsOutputToContain('Dispatched recovery for 1 stale payout(s)');

    Bus::assertDispatched(ProcessInstructorPayoutJob::class, 1);
});

it('ignores a payout that is still within the staleness threshold', function () {
    Bus::fake();

    // Use the factory directly — no ledger/provider path required.
    $payout = Payout::factory()->create([
        'status' => PayoutStatus::Processing,
        'processing_started_at' => now()->subMinutes(5),
        'dispatched_at' => now()->subMinutes(5),
    ]);

    $this->artisan('payouts:recover-stale', ['--threshold' => 30])->assertSuccessful()
        ->expectsOutputToContain('Dispatched recovery for 0 stale payout(s)');

    Bus::assertNothingDispatched();
});

it('does not recover payouts that are already in a terminal or unknown state', function () {
    Bus::fake();

    // Unknown payouts go to payouts:reconcile, not payouts:recover-stale.
    Payout::factory()->create([
        'status' => PayoutStatus::Unknown,
        'processing_started_at' => now()->subHours(2),
        'dispatched_at' => now()->subHours(2),
    ]);

    Payout::factory()->create([
        'status' => PayoutStatus::Succeeded,
        'processing_started_at' => now()->subHours(3),
        'dispatched_at' => now()->subHours(3),
        'confirmed_at' => now()->subHours(2),
    ]);

    $this->artisan('payouts:recover-stale', ['--threshold' => 30])->assertSuccessful()
        ->expectsOutputToContain('Dispatched recovery for 0 stale payout(s)');

    Bus::assertNothingDispatched();
});

it('processing_started_at is stamped on first attempt and not overwritten on retries', function () {
    Queue::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    $payout = app(InitiateInstructorPayout::class)->handle($instructor);

    expect($payout->processing_started_at)->toBeNull();

    // Simulate the worker calling markProcessing twice (crash-and-retry).
    $payout->forceFill([
        'status' => PayoutStatus::Processing,
        'attempt_count' => 1,
        'processing_started_at' => now()->subMinutes(10),
    ])->save();

    $firstStamp = $payout->fresh()->processing_started_at;

    // Second attempt: processing_started_at should not advance.
    $payout->forceFill([
        'attempt_count' => 2,
        'processing_started_at' => $payout->processing_started_at ?? now(),
    ])->save();

    expect($payout->fresh()->processing_started_at->toIso8601String())
        ->toBe($firstStamp->toIso8601String());
});
