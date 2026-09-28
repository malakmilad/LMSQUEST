<?php

use App\Actions\Revenue\RecordSubscriptionPayment;
use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\Ledger\LedgerVerifier;
use App\Services\Payments\MockPaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->use(DatabaseMigrations::class)->in('Integration');

/**
 * Subscribe a student to one course per instructor and record the payment the
 * way production does (allocation + recognition of started periods).
 *
 * @param  array<int, Instructor>|array<int, array{0: Instructor, 1: int}>  $instructors
 *                                                                                        plain instructors (equal split) or [instructor, share_bps] pairs
 */
function subscribe(
    array $instructors,
    SubscriptionPlan $plan = SubscriptionPlan::Monthly,
    ?int $amountMinor = null,
    ?CarbonImmutable $startsAt = null,
): SubscriptionPayment {
    $subscription = Subscription::factory()
        ->plan($plan, $startsAt ?? CarbonImmutable::now())
        ->price($amountMinor ?? $plan->priceMinor())
        ->create();

    foreach ($instructors as $entry) {
        [$instructor, $shareBps] = is_array($entry) ? $entry : [$entry, null];
        $course = Course::factory()->for($instructor)->create();
        $subscription->courses()->attach($course->id, ['revenue_share_bps' => $shareBps]);
    }

    return app(RecordSubscriptionPayment::class)->handle($subscription, 'pay_'.Str::ulid());
}

/** An instructor whose outstanding balance is exactly $outstandingMinor, earned through a real monthly subscription. */
function instructorOwed(int $outstandingMinor = 100_000, array $attributes = []): Instructor
{
    $instructor = Instructor::factory()->create($attributes);
    $instructorPercent = 100 - (int) config('revenue.platform_percentage');

    expect(($outstandingMinor * 100) % $instructorPercent)->toBe(0, 'Pick an amount the platform split divides exactly.');

    subscribe([$instructor], SubscriptionPlan::Monthly, intdiv($outstandingMinor * 100, $instructorPercent));

    expect(balanceOf($instructor)->outstanding_minor)->toBe($outstandingMinor);

    return $instructor;
}

function balanceOf(Instructor $instructor): InstructorBalance
{
    return InstructorBalance::query()->where('instructor_id', $instructor->id)->firstOrFail();
}

function provider(): MockPaymentProvider
{
    return app(MockPaymentProvider::class);
}

function expectBooksToBalance(): void
{
    expect(app(LedgerVerifier::class)->verify())->toBe([]);
}
