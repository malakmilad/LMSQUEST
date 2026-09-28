<?php

use App\Actions\ProcessSubscriptionRefund;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\ProviderReportedStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\InsufficientRefundableAmountException;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Payments\MockPaymentProvider;

it('reverses unearned instructor share when the student is refunded before payout', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    expect($instructor->fresh()->available_balance_cents)->toBe(7000);

    $refund = app(ProcessSubscriptionRefund::class)->handle(
        $scenario['payment'],
        10_000,
        'refund-full-'.$scenario['payment']->id,
        'Left on day 3',
    );

    expect($refund->amount_cents)->toBe(10_000);
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect($scenario['payment']->fresh()->status)->toBe(PaymentStatus::Refunded);
    expect($scenario['subscription']->fresh()->status)->toBe(SubscriptionStatus::Refunded);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();
    expect(Payout::query()->count())->toBe(0);
    assertInstructorCacheMatchesLedger($instructor);
});

it('claws back future payouts if the refund arrives after the instructor was paid', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];

    app(MockPaymentProvider::class)->script(ProviderReportedStatus::Succeeded);
    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();

    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect(Payout::query()->first()->status->value)->toBe('succeeded');

    app(ProcessSubscriptionRefund::class)->handle(
        $scenario['payment'],
        10_000,
        'refund-after-payout-'.$scenario['payment']->id,
    );

    expect($instructor->fresh()->available_balance_cents)->toBe(-7000);

    $this->artisan('payouts:dispatch', ['--min' => 0])->assertSuccessful();
    expect(Payout::query()->count())->toBe(1);
    expect((int) LedgerEntry::query()->where('type', LedgerEntryType::RefundClawback)->sum('amount_cents'))->toBe(-7000);
    assertInstructorCacheMatchesLedger($instructor);
});

it('prorates a mid-term partial refund across the original allocation weights', function () {
    $scenario = paidSubscription([2, 1], priceCents: 10_000);
    $sara = $scenario['instructors'][0];
    $omar = $scenario['instructors'][1];

    app(ProcessSubscriptionRefund::class)->handle(
        $scenario['payment'],
        5_000,
        'refund-half-'.$scenario['payment']->id,
    );

    expect($sara->fresh()->available_balance_cents)->toBe(2334);
    expect($omar->fresh()->available_balance_cents)->toBe(1166);
    expect($sara->fresh()->available_balance_cents + $omar->fresh()->available_balance_cents)->toBe(3500);
    expect($scenario['payment']->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
    assertInstructorCacheMatchesLedger($sara);
    assertInstructorCacheMatchesLedger($omar);
});

it('is idempotent on the refund key', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $action = app(ProcessSubscriptionRefund::class);
    $key = 'refund-once-'.$scenario['payment']->id;

    $action->handle($scenario['payment'], 4_000, $key);
    $action->handle($scenario['payment'], 4_000, $key);

    expect(\App\Models\Refund::query()->count())->toBe(1);
    expect($scenario['instructors'][0]->fresh()->available_balance_cents)->toBe(4200);
});

it('rejects a refund larger than the remaining refundable amount', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);

    app(ProcessSubscriptionRefund::class)->handle($scenario['payment'], 10_000, 'refund-all');

    app(ProcessSubscriptionRefund::class)->handle($scenario['payment'], 1, 'refund-too-much');
})->throws(InsufficientRefundableAmountException::class);
