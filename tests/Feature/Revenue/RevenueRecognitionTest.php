<?php

use App\Actions\Revenue\RecognizeRevenue;
use App\Enums\AllocationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 10:00:00'));
});

it('earns an annual subscription one month at a time: 12,000 EGP becomes 1,000 EGP per month', function () {
    $instructor = Instructor::factory()->create();

    // 15,000 EGP annual, platform 20% => 12,000 EGP for the instructor
    subscribe([$instructor], SubscriptionPlan::Annual, 1_500_000);

    expect(balanceOf($instructor)->earned_minor)->toBe(100_000);   // month 1 started today

    $this->travel(1)->months();
    app(RecognizeRevenue::class)->handle();
    expect(balanceOf($instructor)->earned_minor)->toBe(200_000);

    $this->travel(10)->months();
    app(RecognizeRevenue::class)->handle();
    expect(balanceOf($instructor)->earned_minor)->toBe(1_200_000)
        ->and($instructor->revenueAllocations()->first()->status)->toBe(AllocationStatus::Completed);

    expectBooksToBalance();
});

it('earns a monthly subscription in full as soon as it starts', function () {
    $instructor = Instructor::factory()->create();

    subscribe([$instructor], SubscriptionPlan::Monthly, 30_000);

    expect(balanceOf($instructor)->earned_minor)->toBe(24_000)
        ->and($instructor->revenueAllocations()->first()->status)->toBe(AllocationStatus::Completed);
});

it('does not earn anything for a subscription that starts in the future', function () {
    $instructor = Instructor::factory()->create();

    subscribe([$instructor], SubscriptionPlan::ThreeMonth, 80_000, CarbonImmutable::now()->addWeek());

    expect(balanceOf($instructor)->earned_minor)->toBe(0);

    $this->travel(8)->days();
    app(RecognizeRevenue::class)->handle();

    expect(balanceOf($instructor)->earned_minor)->toBe(21_334);   // 64,000 over 3 periods: 21,334 / 21,333 / 21,333
});

it('catches up every missed month in one run and posts each month exactly once', function () {
    $instructor = Instructor::factory()->create();
    subscribe([$instructor], SubscriptionPlan::Annual, 1_500_000);

    $this->travel(5)->months();
    app(RecognizeRevenue::class)->handle();
    app(RecognizeRevenue::class)->handle();
    $this->artisan('revenue:recognize')->assertSuccessful();

    expect(balanceOf($instructor)->earned_minor)->toBe(600_000)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::Earning)->count())->toBe(6);

    expectBooksToBalance();
});

it('dates each earning entry at the start of the month it belongs to', function () {
    $instructor = Instructor::factory()->create();
    subscribe([$instructor], SubscriptionPlan::ThreeMonth, 80_000);

    $this->travel(3)->months();
    app(RecognizeRevenue::class)->handle();

    expect(LedgerEntry::query()->orderBy('id')->pluck('occurred_at')->map->toDateString()->all())
        ->toBe(['2026-01-15', '2026-02-15', '2026-03-15']);
});

it('marks subscriptions expired once their term is over', function () {
    $instructor = Instructor::factory()->create();
    $payment = subscribe([$instructor], SubscriptionPlan::Monthly);

    $this->travel(1)->months();
    $this->travel(1)->minutes();
    $this->artisan('revenue:recognize')->assertSuccessful();

    expect($payment->subscription->fresh()->status)->toBe(SubscriptionStatus::Expired);
});
