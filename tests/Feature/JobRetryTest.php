<?php

use App\Actions\InitiateInstructorPayout;
use App\Actions\ProcessInstructorPayout;
use App\Enums\ProviderReportedStatus;
use App\Jobs\ProcessInstructorPayoutJob;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use App\Payments\MockPaymentProvider;
use Illuminate\Support\Facades\Bus;

it('does not double-pay when the same payout job is handled twice', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Succeeded);

    $payout = app(InitiateInstructorPayout::class)->handle($instructor);
    $processor = app(ProcessInstructorPayout::class);

    $processor->handle($payout);
    $processor->handle($payout->fresh());

    expect(Payout::query()->count())->toBe(1);
    expect(MockProviderTransfer::query()->count())->toBe(1);
    expect($payout->fresh()->status->value)->toBe('succeeded');
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    assertInstructorCacheMatchesLedger($instructor);
});

it('dispatches a queued payout job after the hold is posted', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);

    $payout = app(InitiateInstructorPayout::class)->handle($scenario['instructors'][0]);

    expect($payout)->not->toBeNull();
    Bus::assertDispatched(ProcessInstructorPayoutJob::class, fn (ProcessInstructorPayoutJob $job) => $job->payout->is($payout));
});

it('keeps money correct if the worker crashes after the provider succeeded', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Succeeded);

    $payout = app(InitiateInstructorPayout::class)->handle($instructor);

    app(MockPaymentProvider::class)->transfer(new \App\Payments\DTO\TransferRequest(
        idempotencyKey: $payout->idempotency_key,
        instructorId: (int) $payout->instructor_id,
        amountCents: (int) $payout->amount_cents,
        currency: $payout->currency,
    ));

    expect($payout->fresh()->status->value)->toBe('pending');
    expect(MockProviderTransfer::query()->count())->toBe(1);

    app(ProcessInstructorPayout::class)->handle($payout->fresh());

    expect($payout->fresh()->status->value)->toBe('succeeded');
    expect(MockProviderTransfer::query()->count())->toBe(1);
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
});
