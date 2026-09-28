<?php

use App\Enums\SubscriptionPlan;
use App\Models\Instructor;

it('splits a 100 unit pool across three instructors as 34/33/33 with the extra unit going to the lowest instructor id', function () {
    $instructors = Instructor::factory()->count(3)->create();

    // 125 paid, platform takes floor(20%) = 25, pool = 100
    $payment = subscribe($instructors->all(), SubscriptionPlan::Monthly, 125);

    expect($payment->instructor_pool_minor)->toBe(100)
        ->and($payment->allocations()->orderBy('instructor_id')->pluck('amount_minor')->all())->toBe([34, 33, 33]);
});

it('gives the same split no matter which order the courses were attached in', function () {
    [$a, $b, $c] = Instructor::factory()->count(3)->create();

    $forward = subscribe([$a, $b, $c], SubscriptionPlan::Monthly, 125);
    $backward = subscribe([$c, $b, $a], SubscriptionPlan::Monthly, 125);

    $split = fn ($payment) => $payment->allocations()->orderBy('instructor_id')->pluck('amount_minor', 'instructor_id')->all();

    expect($split($backward))->toBe($split($forward));
});

it('floors the platform share so rounding never takes money from instructors', function () {
    $instructor = Instructor::factory()->create();

    // 20% of 99 is 19.8: platform gets 19, instructor gets 80
    $payment = subscribe([$instructor], SubscriptionPlan::Monthly, 99);

    expect($payment->platform_share_minor)->toBe(19)
        ->and($payment->instructor_pool_minor)->toBe(80);
});

it('recognizes an allocation that does not divide by twelve without losing a unit', function () {
    $instructor = Instructor::factory()->create();

    $this->travelTo(now()->startOfDay());
    subscribe([$instructor], SubscriptionPlan::Annual, 125);   // instructor allocation = 100

    $this->travel(12)->months();
    $this->artisan('revenue:recognize')->assertSuccessful();

    expect(balanceOf($instructor)->earned_minor)->toBe(100);
    expectBooksToBalance();
});
