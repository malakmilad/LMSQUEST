<?php

use App\Actions\Revenue\AllocateSubscriptionRevenue;
use App\Actions\Revenue\RecordSubscriptionPayment;
use App\Enums\SubscriptionPlan;
use App\Exceptions\RevenueException;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;

it('allocates a payment 50/30/20 between instructors after the platform takes its 20 percent', function () {
    [$sara, $omar, $layla] = Instructor::factory()->count(3)->create();

    $payment = subscribe([[$sara, 5_000], [$omar, 3_000], [$layla, 2_000]], SubscriptionPlan::Monthly, 100_000);

    expect($payment->platform_share_minor)->toBe(20_000)
        ->and($payment->instructor_pool_minor)->toBe(80_000)
        ->and($payment->allocations()->orderBy('instructor_id')->pluck('amount_minor', 'instructor_id')->all())
        ->toBe([$sara->id => 40_000, $omar->id => 24_000, $layla->id => 16_000]);

    expectBooksToBalance();
});

it('keeps platform share plus instructor shares equal to the payment for awkward amounts', function (int $amount) {
    $instructors = Instructor::factory()->count(3)->create();

    $payment = subscribe($instructors->all(), SubscriptionPlan::Monthly, $amount);

    expect($payment->platform_share_minor + $payment->allocations()->sum('amount_minor'))->toBe($amount);
    expectBooksToBalance();
})->with([1, 7, 99, 101, 12_345, 299_999]);

it('reads the platform percentage from config and snapshots it on the payment', function () {
    config(['revenue.platform_percentage' => 30]);
    $instructor = Instructor::factory()->create();

    $payment = subscribe([$instructor], SubscriptionPlan::Monthly, 100_000);
    config(['revenue.platform_percentage' => 20]);

    expect($payment->platform_share_bps)->toBe(3_000)
        ->and($payment->platform_share_minor)->toBe(30_000)
        ->and(balanceOf($instructor)->earned_minor)->toBe(70_000);
});

it('splits the pool per course when no explicit percentages are set', function () {
    [$sara, $omar] = Instructor::factory()->count(2)->create();
    $subscription = Subscription::factory()->price(125_000)->create();
    $subscription->courses()->attach([
        Course::factory()->for($sara)->create()->id,
        Course::factory()->for($sara)->create()->id,
        Course::factory()->for($sara)->create()->id,
        Course::factory()->for($omar)->create()->id,
    ]);

    $payment = app(RecordSubscriptionPayment::class)->handle($subscription, 'pay_courses');

    expect($payment->allocations()->pluck('amount_minor', 'instructor_id')->all())
        ->toBe([$sara->id => 75_000, $omar->id => 25_000]);
});

it('rejects course percentages that do not add up to 100', function () {
    [$sara, $omar] = Instructor::factory()->count(2)->create();

    subscribe([[$sara, 5_000], [$omar, 4_000]]);
})->throws(RevenueException::class, 'must sum to 10000');

it('rejects a subscription where only some courses have a percentage', function () {
    [$sara, $omar] = Instructor::factory()->count(2)->create();

    subscribe([[$sara, 5_000], $omar]);
})->throws(RevenueException::class, 'some courses but not all');

it('leaves nothing behind when allocation is rejected', function () {
    [$sara, $omar] = Instructor::factory()->count(2)->create();

    try {
        subscribe([[$sara, 5_000], [$omar, 4_000]]);
    } catch (RevenueException) {
    }

    expect(SubscriptionPayment::query()->count())->toBe(0)
        ->and(RevenueAllocation::query()->count())->toBe(0);
});

it('records a payment delivered twice by the provider only once', function () {
    $instructor = Instructor::factory()->create();
    $subscription = Subscription::factory()->create();
    $subscription->courses()->attach(Course::factory()->for($instructor)->create());

    $first = app(RecordSubscriptionPayment::class)->handle($subscription, 'pay_webhook_1');
    $second = app(RecordSubscriptionPayment::class)->handle($subscription, 'pay_webhook_1');

    expect($second->id)->toBe($first->id)
        ->and(SubscriptionPayment::query()->count())->toBe(1)
        ->and(RevenueAllocation::query()->count())->toBe(1)
        ->and(balanceOf($instructor)->earned_minor)->toBe(24_000);
});

it('allocates a payment only once even if allocation is triggered again', function () {
    $instructor = Instructor::factory()->create();
    $payment = subscribe([$instructor]);

    app(AllocateSubscriptionRevenue::class)->handle($payment);
    app(AllocateSubscriptionRevenue::class)->handle($payment);

    expect(RevenueAllocation::query()->count())->toBe(1);
    expectBooksToBalance();
});

it('keeps the whole payment for the platform when the subscription has no courses', function () {
    $subscription = Subscription::factory()->create();

    $payment = app(RecordSubscriptionPayment::class)->handle($subscription, 'pay_empty');

    expect($payment->platform_share_minor)->toBe($payment->amount_minor)
        ->and($payment->instructor_pool_minor)->toBe(0);
    expectBooksToBalance();
});

it('refuses to pay an instructor in a currency different from the payment', function () {
    $instructor = Instructor::factory()->create(['currency' => 'USD']);

    subscribe([$instructor]);
})->throws(RevenueException::class, 'is paid in USD');
