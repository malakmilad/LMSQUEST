<?php

use App\Actions\InitiateInstructorPayout;
use App\Actions\ProcessInstructorPayout;
use App\Enums\ProviderActualStatus;
use App\Enums\ProviderReportedStatus;
use App\Jobs\ReconcileUnknownPayoutJob;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use App\Payments\MockPaymentProvider;
use Illuminate\Support\Facades\Bus;

it('does not treat a timeout as a failure and will not pay that amount again', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Timeout);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    $payout = Payout::query()->first();
    $transfer = MockProviderTransfer::query()->first();

    expect($payout->status->value)->toBe('unknown');
    expect($transfer->actual_status)->toBe(ProviderActualStatus::Succeeded);
    expect($transfer->reported_status)->toBe(ProviderReportedStatus::Timeout);
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect($instructor->fresh()->in_flight_payout_id)->toBe($payout->id);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    expect(Payout::query()->count())->toBe(1);
    expect(MockProviderTransfer::query()->count())->toBe(1);
});

it('dispatches one ReconcileUnknownPayoutJob per unknown payout', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    // Initiate the payout (Bus is faked so ProcessInstructorPayoutJob is not executed).
    app(\App\Actions\InitiateInstructorPayout::class)->handle($instructor);

    // Force the payout to Unknown to simulate the state payouts:reconcile acts on.
    $payout = Payout::query()->first();
    $payout->forceFill(['status' => \App\Enums\PayoutStatus::Unknown])->save();

    // Reset the fake so only the reconcile dispatch is captured.
    Bus::fake();

    $this->artisan('payouts:reconcile')->assertSuccessful()
        ->expectsOutputToContain('Dispatched reconciliation for 1 unknown payout(s).');

    Bus::assertDispatched(ReconcileUnknownPayoutJob::class, 1);
    Bus::assertDispatched(ReconcileUnknownPayoutJob::class, function (ReconcileUnknownPayoutJob $job) use ($payout) {
        return $job->payout->id === $payout->id;
    });
});

it('confirms a timeout-after-success through the reconciliation job', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Timeout);

    // Bus is faked so InitiateInstructorPayout does not auto-run the job.
    $payout = app(InitiateInstructorPayout::class)->handle($instructor);

    // Manually drive the process action so the payout reaches Unknown.
    $processor = app(ProcessInstructorPayout::class);
    $processor->handle($payout->fresh());

    expect($payout->fresh()->status->value)->toBe('unknown');

    // Running reconcile() directly simulates the queue worker executing
    // the ReconcileUnknownPayoutJob dispatched by payouts:reconcile.
    $processor->reconcile($payout->fresh());

    $payout->refresh();

    expect($payout->status->value)->toBe('succeeded');
    expect($payout->confirmed_at)->not->toBeNull();
    expect($instructor->fresh()->in_flight_payout_id)->toBeNull();
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect(MockProviderTransfer::query()->count())->toBe(1);
    assertInstructorCacheMatchesLedger($instructor);
});

it('running payouts:reconcile twice dispatches jobs only for still-unknown payouts', function () {
    // First run should dispatch 1 job; second run (before job runs) should also
    // dispatch 1 job — but the ShouldBeUnique lock on ReconcileUnknownPayoutJob
    // prevents duplicate processing. Here we just verify the command counts correctly.
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    // Bus is faked: InitiateInstructorPayout dispatches a job but it does not run.
    app(\App\Actions\InitiateInstructorPayout::class)->handle($instructor);

    // Force the payout to Unknown to simulate the state payouts:reconcile acts on.
    $payout = Payout::query()->first();
    $payout->forceFill(['status' => \App\Enums\PayoutStatus::Unknown])->save();

    $this->artisan('payouts:reconcile')->assertSuccessful()
        ->expectsOutputToContain('Dispatched reconciliation for 1 unknown payout(s).');

    // Payout is still Unknown (job not yet run), so second reconcile also dispatches.
    $this->artisan('payouts:reconcile')->assertSuccessful()
        ->expectsOutputToContain('Dispatched reconciliation for 1 unknown payout(s).');
});

it('turns a retried timeout into success because the provider already moved the money', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Timeout);

    $payout = app(InitiateInstructorPayout::class)->handle($instructor);
    $processor = app(ProcessInstructorPayout::class);

    expect($processor->handle($payout)->status->value)->toBe('unknown');
    expect($processor->handle($payout->fresh())->status->value)->toBe('succeeded');
    expect(MockProviderTransfer::query()->count())->toBe(1);
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
});
