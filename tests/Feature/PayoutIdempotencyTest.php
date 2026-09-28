<?php

use App\Actions\DispatchInstructorPayouts;
use App\Actions\InitiateInstructorPayout;
use App\Actions\ProcessInstructorPayout;
use App\Enums\ProviderReportedStatus;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use App\Payments\MockPaymentProvider;
use Illuminate\Support\Facades\Bus;

it('never double-pays when the payout command runs twice', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(
        ProviderReportedStatus::Succeeded,
        ProviderReportedStatus::Succeeded,
    );

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();
    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    expect(Payout::query()->count())->toBe(1);
    expect(MockProviderTransfer::query()->count())->toBe(1);
    expect(Payout::query()->first()->status->value)->toBe('succeeded');
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect($instructor->fresh()->in_flight_payout_id)->toBeNull();
    assertInstructorCacheMatchesLedger($instructor);
});

it('skips an instructor who already has an in-flight payout', function () {
    Bus::fake();

    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    $first = app(InitiateInstructorPayout::class)->handle($instructor);
    $second = app(InitiateInstructorPayout::class)->handle($instructor->fresh());
    $batch = app(DispatchInstructorPayouts::class)->handle(minCents: 0);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect($batch)->toBe([]);
    expect(Payout::query()->count())->toBe(1);
});

it('releases the hold and allows a later payout after a permanent provider failure', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(
        ProviderReportedStatus::Failed,
        ProviderReportedStatus::Succeeded,
    );

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    expect(Payout::query()->first()->status->value)->toBe('failed');
    expect($instructor->fresh()->available_balance_cents)->toBe(7000);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    expect(Payout::query()->count())->toBe(2);
    expect(Payout::query()->where('status', 'succeeded')->count())->toBe(1);
    expect(MockProviderTransfer::query()->count())->toBe(2);
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    assertInstructorCacheMatchesLedger($instructor);
});
