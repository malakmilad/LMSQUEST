<?php

use App\Actions\Payouts\CreateInstructorPayout;
use App\Actions\Payouts\ProcessPayout;
use App\Actions\Revenue\RecognizeRevenue;
use App\Actions\Revenue\RefundSubscriptionPayment;
use App\Enums\AllocationStatus;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Enums\RefundType;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\RevenueException;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Refund;
use App\Models\SubscriptionPayment;
use App\Services\Ledger\LedgerRecorder;
use App\Services\Money\Allocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function refund(SubscriptionPayment $payment, RefundType $type = RefundType::Full): Refund
{
    return app(RefundSubscriptionPayment::class)->handle($payment, $type, 'customer request');
}

it('reverses recognized earnings with new ledger entries on a full refund and deletes nothing', function () {
    [$sara, $omar] = Instructor::factory()->count(2)->create();
    $payment = subscribe([[$sara, 6_000], [$omar, 4_000]], SubscriptionPlan::Monthly, 100_000);
    $entriesBefore = LedgerEntry::query()->pluck('id')->all();

    $refund = refund($payment);

    expect($refund->amount_minor)->toBe(100_000)
        ->and(LedgerEntry::query()->whereIn('id', $entriesBefore)->count())->toBe(count($entriesBefore))
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::RefundReversal)->orderBy('instructor_id')->pluck('amount_minor')->all())
        ->toBe([-48_000, -32_000]);

    expect(balanceOf($sara))
        ->earned_minor->toBe(48_000)
        ->reversed_minor->toBe(48_000)
        ->outstanding_minor->toBe(0)
        ->recoverable_minor->toBe(0);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->subscription->fresh()->status)->toBe(SubscriptionStatus::Refunded)
        ->and($payment->allocations()->pluck('status')->unique()->all())->toBe([AllocationStatus::Cancelled]);

    expectBooksToBalance();
});

it('refunds a payment only once no matter how many times the refund is requested', function () {
    $instructor = Instructor::factory()->create();
    $payment = subscribe([$instructor]);

    $first = refund($payment);
    $second = refund($payment);

    expect($second->id)->toBe($first->id)
        ->and(Refund::query()->count())->toBe(1)
        ->and(LedgerEntry::query()->where('type', LedgerEntryType::RefundReversal)->count())->toBe(1)
        ->and(balanceOf($instructor)->reversed_minor)->toBe(24_000);
});

it('turns a refund after payout into a recoverable 500 EGP that future earnings pay back', function () {
    $instructor = Instructor::factory()->create();
    $first = subscribe([$instructor], amountMinor: 62_500);    // instructor earns 500 EGP
    subscribe([$instructor], amountMinor: 62_500);             // and another 500 EGP

    $this->artisan('payouts:process');
    expect(balanceOf($instructor)->paid_minor)->toBe(100_000);

    refund($first);

    expect(balanceOf($instructor))
        ->outstanding_minor->toBe(0)
        ->recoverable_minor->toBe(50_000);
    expect(Payout::query()->sole()->status)->toBe(PayoutStatus::Paid);

    // Nothing is paid while the instructor owes us.
    $this->artisan('payouts:process');
    expect(Payout::query()->count())->toBe(1);

    // New earnings are offset against the recoverable balance first.
    subscribe([$instructor], amountMinor: 37_500);   // +300 EGP
    expect(balanceOf($instructor))->recoverable_minor->toBe(20_000)->outstanding_minor->toBe(0);

    subscribe([$instructor], amountMinor: 125_000);  // +1,000 EGP
    expect(balanceOf($instructor))->recoverable_minor->toBe(0)->outstanding_minor->toBe(80_000);

    $this->artisan('payouts:process');
    expect(Payout::query()->latest('id')->first()->amount_minor)->toBe(80_000);

    expectBooksToBalance();
});

it('creates a recoverable balance when the refund lands while the payout is in flight', function () {
    $instructor = Instructor::factory()->create();
    $payment = subscribe([$instructor], amountMinor: 125_000);   // instructor earns 1,000 EGP
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    provider()->beforeTransfer(fn () => refund($payment));

    expect(app(ProcessPayout::class)->handle($payout->id))->toBe(PayoutAttemptResult::Paid);
    expect(balanceOf($instructor))
        ->paid_minor->toBe(100_000)
        ->reversed_minor->toBe(100_000)
        ->recoverable_minor->toBe(100_000);

    expectBooksToBalance();
});

it('cancels a payout that has not been sent yet when a refund shrinks the balance', function () {
    $instructor = Instructor::factory()->create();
    $payment = subscribe([$instructor], amountMinor: 125_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    refund($payment);

    expect($payout->fresh()->status)->toBe(PayoutStatus::Cancelled)
        ->and($payout->fresh()->open_instructor_id)->toBeNull()
        ->and(provider()->payoutCalls)->toBe(0);

    expectBooksToBalance();
});

it('refuses to send a queued payout larger than the balance it was created from', function () {
    $instructor = instructorOwed(100_000);
    $payout = app(CreateInstructorPayout::class)->handle($instructor->id);

    DB::transaction(fn () => app(LedgerRecorder::class)->record(
        $instructor->id, LedgerEntryType::Adjustment, -30_000, 'EGP', 'adjustment:test:chargeback-fee',
    ));

    expect(app(ProcessPayout::class)->handle($payout->id))->toBe(PayoutAttemptResult::Cancelled)
        ->and(provider()->payoutCalls)->toBe(0);

    $this->artisan('payouts:process');
    expect(Payout::query()->latest('id')->first())
        ->amount_minor->toBe(70_000)
        ->status->toBe(PayoutStatus::Paid);
});

describe('prorated refunds', function () {
    beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-01-15 10:00:00')));

    it('refunds only the months that have not started and keeps started months earned', function () {
        $instructor = Instructor::factory()->create();
        $payment = subscribe([$instructor], SubscriptionPlan::Annual, 1_500_000);   // 125,000 gross per month

        $this->travelTo(CarbonImmutable::parse('2026-04-20 09:00:00'));             // months 1-4 have started
        $refund = refund($payment, RefundType::Prorated);

        expect($refund->amount_minor)->toBe(1_000_000)
            ->and($refund->periods_refunded)->toBe(8)
            ->and(balanceOf($instructor)->earned_minor)->toBe(400_000)
            ->and(LedgerEntry::query()->where('type', LedgerEntryType::RefundReversal)->count())->toBe(0)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
            ->and($payment->subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

        // Instructor earned + platform kept + refunded = what the student paid.
        $platformKept = 4 * 25_000;
        expect(balanceOf($instructor)->earned_minor + $platformKept + $refund->amount_minor)->toBe(1_500_000);

        // Cancelled months are never recognized later.
        $this->travel(1)->year();
        app(RecognizeRevenue::class)->handle();
        expect(balanceOf($instructor)->earned_minor)->toBe(400_000);

        expectBooksToBalance();
    });

    it('recognizes months that started before the refund even if the recognizer has not run yet', function () {
        $instructor = Instructor::factory()->create();
        $payment = subscribe([$instructor], SubscriptionPlan::Annual, 1_500_000);

        $this->travelTo(CarbonImmutable::parse('2026-03-16 00:00:00'));   // no revenue:recognize since January
        refund($payment, RefundType::Prorated);

        expect(balanceOf($instructor)->earned_minor)->toBe(300_000);
    });

    it('splits odd amounts so earned plus refunded is exactly the payment', function () {
        [$a, $b, $c] = Instructor::factory()->count(3)->create();
        $payment = subscribe([$a, $b, $c], SubscriptionPlan::Annual, 1_000_003);

        $this->travelTo(CarbonImmutable::parse('2026-06-01 00:00:00'));
        $refund = refund($payment, RefundType::Prorated);

        $earned = LedgerEntry::query()->where('type', LedgerEntryType::Earning)->sum('amount_minor');
        $platformKept = array_sum(array_slice(Allocator::evenly($payment->platform_share_minor, 12), 0, 5));

        expect($earned + $platformKept + $refund->amount_minor)->toBe(1_000_003);
        expectBooksToBalance();
    });

    it('refunds everything when the subscription has not started yet', function () {
        $instructor = Instructor::factory()->create();
        $payment = subscribe([$instructor], SubscriptionPlan::ThreeMonth, 80_000, CarbonImmutable::now()->addWeek());

        $refund = refund($payment, RefundType::Prorated);

        expect($refund->amount_minor)->toBe(80_000)
            ->and($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
            ->and(balanceOf($instructor)->earned_minor)->toBe(0);
    });

    it('rejects a prorated refund once every month has started', function () {
        $payment = subscribe([Instructor::factory()->create()], SubscriptionPlan::Monthly);

        refund($payment, RefundType::Prorated);
    })->throws(RevenueException::class, 'prorated refund would be zero');
});

it('does not refund a payment that never succeeded', function () {
    $payment = SubscriptionPayment::factory()->failed()->create();

    refund($payment);
})->throws(RevenueException::class, 'cannot be refunded');

it('refunds from the command line', function () {
    $payment = subscribe([Instructor::factory()->create()], amountMinor: 30_000);

    $this->artisan('subscriptions:refund', ['payment' => $payment->id])
        ->expectsOutputToContain('EGP 300.00 returned to the student')
        ->assertSuccessful();
});
