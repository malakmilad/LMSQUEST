<?php

use App\Actions\RecordSubscriptionPayment;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\SubscriptionPayment;

it('splits a payment by enrolled course count after the platform cut', function () {
    $scenario = paidSubscription([2, 1], priceCents: 10_000, shareBps: 7_000);

    $sara = $scenario['instructors'][0]->fresh();
    $omar = $scenario['instructors'][1]->fresh();

    expect($sara->available_balance_cents)->toBe(4667);
    expect($omar->available_balance_cents)->toBe(2333);
    expect($sara->available_balance_cents + $omar->available_balance_cents)->toBe(7000);

    $platform = (int) LedgerEntry::query()
        ->where('type', LedgerEntryType::PlatformFee)
        ->where('subscription_payment_id', $scenario['payment']->id)
        ->sum('amount_cents');

    expect($platform)->toBe(3000);
    expect($sara->available_balance_cents + $omar->available_balance_cents + $platform)->toBe(10_000);

    assertInstructorCacheMatchesLedger($sara);
    assertInstructorCacheMatchesLedger($omar);
});

it('gives leftover cents to the lowest instructor id when remainders tie', function () {
    $scenario = paidSubscription([1, 1, 1], priceCents: 100, shareBps: 7_000);

    $balances = collect($scenario['instructors'])
        ->map(fn ($instructor) => $instructor->fresh()->available_balance_cents)
        ->all();

    expect(array_sum($balances))->toBe(70);
    expect(max($balances))->toBe(24);
    expect(min($balances))->toBe(23);
});

it('gives the platform 100% when the student has no enrollments', function () {
    $scenario = paidSubscription([], priceCents: 10_000, shareBps: 7_000);

    expect(RevenueAllocation::query()->count())->toBe(0);
    expect((int) LedgerEntry::query()->where('type', LedgerEntryType::InstructorEarning)->sum('amount_cents'))->toBe(0);
    expect((int) LedgerEntry::query()->where('type', LedgerEntryType::PlatformFee)->sum('amount_cents'))->toBe(10_000);
});

it('does not allocate twice for the same payment idempotency key', function () {
    $scenario = paidSubscription([1, 1], priceCents: 10_000);

    app(RecordSubscriptionPayment::class)->handle($scenario['subscription'], 'pay-'.$scenario['subscription']->id);
    app(RecordSubscriptionPayment::class)->handle($scenario['subscription'], 'pay-'.$scenario['subscription']->id);

    expect(SubscriptionPayment::query()->count())->toBe(1);
    expect(RevenueAllocation::query()->count())->toBe(2);
    expect(LedgerEntry::query()->where('type', LedgerEntryType::InstructorEarning)->count())->toBe(2);
});

it('snapshots weights at payment time so later enrollments do not rewrite history', function () {
    $scenario = paidSubscription([1], priceCents: 10_000);
    $instructor = $scenario['instructors'][0];
    $original = $instructor->fresh()->available_balance_cents;

    $newInstructor = \App\Models\Instructor::factory()->create();
    $course = \App\Models\Course::factory()->for($newInstructor)->create();
    \App\Models\Enrollment::factory()->for($scenario['subscription'])->for($course)->create();

    expect($instructor->fresh()->available_balance_cents)->toBe($original);
    expect($newInstructor->fresh()->available_balance_cents)->toBe(0);
});
