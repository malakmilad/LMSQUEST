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

it('fragmented refunds claw back exactly the correct cumulative amount (no penny leak)', function () {
    // 101-cent payment, 70 % instructor pool = 70 cents (floor of 70.7).
    // Refunded in two steps: 50 cents then 51 cents (total = 101, fully refunded).
    //
    // Old per-refund calculation:
    //   refund 1: floor(70 * 50 / 101) = 34
    //   refund 2: floor(70 * 51 / 101) = 35   →  total = 69  (1 cent leaked)
    //
    // Cumulative approach:
    //   after refund 1: target = intdiv(70 * 50 / 101) = 34, prior = 0  → post 34
    //   after refund 2: target = intdiv(70 * 101 / 101) = 70, prior = 34 → post 36
    //   total = 70  ✓

    $scenario = paidSubscription([1], priceCents: 101, shareBps: 7_000);
    $instructor = $scenario['instructors'][0];
    $payment    = $scenario['payment'];
    $action     = app(ProcessSubscriptionRefund::class);

    // After payment: instructor earned floor(101 * 0.70) = 70 cents.
    expect($instructor->fresh()->available_balance_cents)->toBe(70);

    $action->handle($payment, 50, 'refund-frag-1-'.$payment->id);
    expect($instructor->fresh()->available_balance_cents)->toBe(36); // 70 - 34

    $action->handle($payment, 51, 'refund-frag-2-'.$payment->id);
    expect($instructor->fresh()->available_balance_cents)->toBe(0); // 70 - 70

    expect($payment->fresh()->status)->toBe(\App\Enums\PaymentStatus::Refunded);
    assertInstructorCacheMatchesLedger($instructor);
});

it('fragmented refund across two instructors leaves zero residual when fully refunded', function () {
    // 10 000-cent payment, 70 % pool = 7 000. Sara has 2 courses, Omar 1.
    // Split: Sara 4667, Omar 2333.
    // Two partial refunds: 4 000 then 6 000 (total = 10 000).

    $scenario = paidSubscription([2, 1], priceCents: 10_000, shareBps: 7_000);
    $sara      = $scenario['instructors'][0];
    $omar      = $scenario['instructors'][1];
    $payment   = $scenario['payment'];
    $action    = app(ProcessSubscriptionRefund::class);

    $action->handle($payment, 4_000, 'refund-frag2-1-'.$payment->id);
    $action->handle($payment, 6_000, 'refund-frag2-2-'.$payment->id);

    // Both instructors should be fully clawed back to zero.
    expect($sara->fresh()->available_balance_cents)->toBe(0);
    expect($omar->fresh()->available_balance_cents)->toBe(0);

    expect($payment->fresh()->status)->toBe(\App\Enums\PaymentStatus::Refunded);
    assertInstructorCacheMatchesLedger($sara);
    assertInstructorCacheMatchesLedger($omar);
});

it('final fragmented refund bringing payment to 100% claws back the full pool', function () {
    // Three partial refunds that together equal the full payment amount.
    $scenario = paidSubscription([1], priceCents: 300, shareBps: 7_000);
    $instructor = $scenario['instructors'][0];
    $payment    = $scenario['payment'];
    $action     = app(ProcessSubscriptionRefund::class);

    // Pool = floor(300 * 0.70) = 210 cents.
    expect($instructor->fresh()->available_balance_cents)->toBe(210);

    $action->handle($payment, 100, 'refund-frag3-1-'.$payment->id);
    $action->handle($payment, 100, 'refund-frag3-2-'.$payment->id);
    $action->handle($payment, 100, 'refund-frag3-3-'.$payment->id);

    // Fully refunded — instructor balance must be exactly 0.
    expect($instructor->fresh()->available_balance_cents)->toBe(0);
    expect($payment->fresh()->status)->toBe(\App\Enums\PaymentStatus::Refunded);
    assertInstructorCacheMatchesLedger($instructor);
});
