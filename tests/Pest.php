<?php

use App\Actions\RecordSubscriptionPayment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * @param  array<int, int>  $courseCounts  enrolled course count per instructor
 * @return array{plan: Plan, student: User, subscription: Subscription, instructors: list<Instructor>, payment: \App\Models\SubscriptionPayment}
 */
function paidSubscription(array $courseCounts, int $priceCents = 10_000, int $shareBps = 7_000): array
{
    $plan = Plan::factory()->create([
        'price_cents' => $priceCents,
        'instructor_share_bps' => $shareBps,
    ]);

    $student = User::factory()->student()->create();
    $subscription = Subscription::factory()->for($student)->for($plan)->create([
        'ends_at' => now()->addDays($plan->duration_days),
    ]);

    $instructors = [];

    foreach ($courseCounts as $count) {
        $instructor = Instructor::factory()->create();
        $instructors[] = $instructor;

        for ($i = 0; $i < $count; $i++) {
            $course = Course::factory()->for($instructor)->create();
            Enrollment::factory()->for($subscription)->for($course)->create();
        }
    }

    $payment = app(RecordSubscriptionPayment::class)->handle($subscription, 'pay-'.$subscription->id);

    return compact('plan', 'student', 'subscription', 'instructors', 'payment');
}

function assertInstructorCacheMatchesLedger(Instructor $instructor): void
{
    $instructor->refresh();
    $sum = (int) LedgerEntry::query()->where('instructor_id', $instructor->id)->sum('amount_cents');

    expect((int) $instructor->available_balance_cents)->toBe($sum);
}
