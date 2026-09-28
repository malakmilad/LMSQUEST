<?php

namespace Database\Seeders;

use App\Actions\ProcessSubscriptionRefund;
use App\Actions\RecordSubscriptionPayment;
use App\Enums\ProviderReportedStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instructor;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\MockPaymentProvider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        config(['queue.default' => 'sync']);

        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@lms.test',
            'role' => UserRole::Admin,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $monthly = Plan::factory()->monthly()->create();
        $quarterly = Plan::factory()->quarterly()->create();
        $annual = Plan::factory()->annual()->create();

        $sara = Instructor::factory()->create([
            'user_id' => User::factory()->instructor()->create([
                'name' => 'Sara Hassan',
                'email' => 'sara@lms.test',
            ])->id,
        ]);
        $omar = Instructor::factory()->create([
            'user_id' => User::factory()->instructor()->create([
                'name' => 'Omar Farid',
                'email' => 'omar@lms.test',
            ])->id,
        ]);
        $layla = Instructor::factory()->create([
            'user_id' => User::factory()->instructor()->create([
                'name' => 'Layla Nabil',
                'email' => 'layla@lms.test',
            ])->id,
        ]);

        $saraCourseA = Course::factory()->for($sara)->create(['title' => 'Laravel from scratch']);
        $saraCourseB = Course::factory()->for($sara)->create(['title' => 'Livewire v3 workshop']);
        $omarCourse = Course::factory()->for($omar)->create(['title' => 'System design for PHP']);
        $laylaCourse = Course::factory()->for($layla)->create(['title' => 'Career strategy lab']);

        $studentA = User::factory()->student()->create([
            'name' => 'Nour Ali',
            'email' => 'nour@lms.test',
        ]);
        $studentB = User::factory()->student()->create([
            'name' => 'Karim Adel',
            'email' => 'karim@lms.test',
        ]);
        $studentC = User::factory()->student()->create([
            'name' => 'Mona Saeed',
            'email' => 'mona@lms.test',
        ]);

        $pay = app(RecordSubscriptionPayment::class);
        $refund = app(ProcessSubscriptionRefund::class);

        $subA = Subscription::factory()->for($studentA)->for($monthly)->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);
        Enrollment::factory()->for($subA)->for($saraCourseA)->create();
        Enrollment::factory()->for($subA)->for($omarCourse)->create();
        $pay->handle($subA, 'seed-pay-nour-monthly');

        $subB = Subscription::factory()->for($studentB)->for($annual)->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->addDays(325),
        ]);
        Enrollment::factory()->for($subB)->for($saraCourseA)->create();
        Enrollment::factory()->for($subB)->for($saraCourseB)->create();
        Enrollment::factory()->for($subB)->for($laylaCourse)->create();
        $paymentB = $pay->handle($subB, 'seed-pay-karim-annual');
        $refund->handle($paymentB, intdiv((int) $paymentB->amount_cents, 2), 'seed-refund-karim-half', 'Left after 6 weeks');

        $subC = Subscription::factory()->for($studentC)->for($quarterly)->create([
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(85),
        ]);
        Enrollment::factory()->for($subC)->for($omarCourse)->create();
        Enrollment::factory()->for($subC)->for($laylaCourse)->create();
        $pay->handle($subC, 'seed-pay-mona-quarterly');

        app(MockPaymentProvider::class)->script(
            ProviderReportedStatus::Succeeded,
            ProviderReportedStatus::Timeout,
            ProviderReportedStatus::Failed,
        );

        Artisan::call('payouts:dispatch', ['--min' => 0]);
        Artisan::call('payouts:reconcile');
    }
}
