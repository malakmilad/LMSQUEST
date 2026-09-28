<?php

use App\Actions\InitiateInstructorPayout;
use App\Actions\ProcessInstructorPayout;
use App\Enums\ProviderActualStatus;
use App\Enums\ProviderReportedStatus;
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

it('confirms a timeout-after-success through the status check', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Timeout);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();
    $this->artisan('payouts:reconcile')->assertSuccessful();

    $payout = Payout::query()->first();

    expect($payout->status->value)->toBe('succeeded');
    expect($payout->confirmed_at)->not->toBeNull();
    expect($instructor->fresh()->in_flight_payout_id)->toBeNull();
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect(MockProviderTransfer::query()->count())->toBe(1);
    assertInstructorCacheMatchesLedger($instructor);
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
