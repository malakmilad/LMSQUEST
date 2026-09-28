<?php

namespace Database\Seeders;

use App\Actions\Payouts\CreateInstructorPayout;
use App\Actions\Payouts\ProcessPayout;
use App\Actions\Revenue\RecordSubscriptionPayment;
use App\Actions\Revenue\RefundSubscriptionPayment;
use App\Enums\RefundType;
use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data built through the same actions production uses, so every number
 * on the admin screen is backed by real ledger entries.
 *
 * Resulting payout states:
 *   Sara, Omar  paid, then new earnings outstanding
 *   Layla       failed (provider rejects acct_fail_*), still outstanding
 *   Karim       unknown (provider times out after paying acct_timeout_*);
 *               `php artisan payouts:reconcile` resolves it to paid
 *   Nadia       paid, then a full refund => recoverable balance
 */
class DatabaseSeeder extends Seeder
{
    private int $sequence = 0;

    public function run(): void
    {
        config(['payments.mock.mode' => 'success']);
        $now = CarbonImmutable::now();

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@lms.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $sara = $this->instructor('Sara Ahmed', 'sara@lms.test', 'acct_sara_eg_001');
        $omar = $this->instructor('Omar Khaled', 'omar@lms.test', 'acct_omar_eg_002');
        $layla = $this->instructor('Layla Mostafa', 'layla@lms.test', 'acct_fail_layla_003');
        $karim = $this->instructor('Karim Youssef', 'karim@lms.test', 'acct_timeout_karim_004');
        $nadia = $this->instructor('Nadia Hassan', 'nadia@lms.test', 'acct_nadia_eg_005');

        $courses = [
            'sara' => Course::query()->create(['title' => 'Laravel from Zero', 'instructor_id' => $sara->id]),
            'omar' => Course::query()->create(['title' => 'System Design for PHP', 'instructor_id' => $omar->id]),
            'layla' => Course::query()->create(['title' => 'UI Design Fundamentals', 'instructor_id' => $layla->id]),
            'karim' => Course::query()->create(['title' => 'Data Analysis with SQL', 'instructor_id' => $karim->id]),
            'nadia' => Course::query()->create(['title' => 'Career Strategy Lab', 'instructor_id' => $nadia->id]),
        ];

        // Annual bundle split 50/30/20, four months in: 4 of 12 months recognized.
        $this->subscribe(SubscriptionPlan::Annual, $now->subMonths(3)->subDays(4), [
            [$courses['sara'], 5_000], [$courses['omar'], 3_000], [$courses['layla'], 2_000],
        ]);

        foreach (range(1, 3) as $_) {
            $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(10), [[$courses['sara'], null]]);
        }
        foreach (range(1, 2) as $_) {
            $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(12), [[$courses['omar'], null]]);
            $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(8), [[$courses['layla'], null]]);
        }
        foreach (range(1, 3) as $_) {
            $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(6), [[$courses['karim'], null]]);
        }
        $this->subscribe(SubscriptionPlan::ThreeMonth, $now->subMonths(1)->subDays(2), [
            [$courses['omar'], null], [$courses['karim'], null],
        ]);

        $nadiaRefunded = $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(15), [[$courses['nadia'], null]]);
        $this->subscribe(SubscriptionPlan::Monthly, $now->subDays(14), [[$courses['nadia'], null]]);

        // Annual plan cancelled five months in: prorated refund of the seven unstarted months.
        $cancelled = $this->subscribe(SubscriptionPlan::Annual, $now->subMonths(4)->subDays(20), [[$courses['omar'], null]]);
        app(RefundSubscriptionPayment::class)->handle($cancelled, RefundType::Prorated, 'Student changed careers');

        // A card decline: recorded, never allocated, earns nobody anything.
        SubscriptionPayment::factory()->failed()->create([
            'subscription_id' => $this->subscription(SubscriptionPlan::Monthly, $now->subDays(3), [[$courses['sara'], null]])->id,
            'provider_reference' => 'seed_pay_declined',
        ]);

        // Pay everyone who is owed. Called directly (not queued) so the seed is deterministic.
        InstructorBalance::query()->where('outstanding_minor', '>', 0)->orderBy('instructor_id')->each(function (InstructorBalance $balance) {
            if ($payout = app(CreateInstructorPayout::class)->handle($balance->instructor_id)) {
                app(ProcessPayout::class)->handle($payout->id);
            }
        });

        // Student asks for their money back after Nadia was paid for it.
        app(RefundSubscriptionPayment::class)->handle($nadiaRefunded, RefundType::Full, 'Duplicate purchase');

        // New earnings after the payout run.
        $this->subscribe(SubscriptionPlan::Monthly, $now->subDay(), [[$courses['sara'], null]]);
        $this->subscribe(SubscriptionPlan::ThreeMonth, $now->subDay(), [[$courses['omar'], null]]);
    }

    private function instructor(string $name, string $email, string $account): Instructor
    {
        return Instructor::query()->create([
            'name' => $name,
            'email' => $email,
            'payout_account_reference' => $account,
            'currency' => 'EGP',
        ]);
    }

    /** @param list<array{0: Course, 1: int|null}> $courses */
    private function subscribe(SubscriptionPlan $plan, CarbonImmutable $startsAt, array $courses): SubscriptionPayment
    {
        $subscription = $this->subscription($plan, $startsAt, $courses);

        return app(RecordSubscriptionPayment::class)->handle(
            $subscription,
            'seed_pay_'.str_pad((string) ++$this->sequence, 4, '0', STR_PAD_LEFT),
            paidAt: $startsAt,
        );
    }

    /** @param list<array{0: Course, 1: int|null}> $courses */
    private function subscription(SubscriptionPlan $plan, CarbonImmutable $startsAt, array $courses): Subscription
    {
        $subscription = Subscription::factory()
            ->for(Student::factory())
            ->plan($plan, $startsAt)
            ->create();

        foreach ($courses as [$course, $shareBps]) {
            $subscription->courses()->attach($course->id, ['revenue_share_bps' => $shareBps]);
        }

        return $subscription;
    }
}
