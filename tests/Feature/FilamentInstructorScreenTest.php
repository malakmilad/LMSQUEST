<?php

use App\Actions\Revenue\RefundSubscriptionPayment;
use App\Enums\MockOutcome;
use App\Enums\PayoutStatus;
use App\Enums\RefundType;
use App\Filament\Resources\InstructorResource;
use App\Filament\Resources\InstructorResource\Pages\ListInstructors;
use App\Filament\Resources\InstructorResource\Pages\ViewInstructor;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Jobs\ReconcilePayoutJob;
use App\Models\Instructor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('requires a signed-in staff member', function () {
    auth()->logout();

    $this->get(InstructorResource::getUrl('index'))->assertRedirect();
});

it('lists every instructor with earned, paid, outstanding and recoverable totals', function () {
    $sara = instructorOwed(100_000, ['name' => 'Sara Ahmed']);
    $nadia = Instructor::factory()->create(['name' => 'Nadia Hassan']);
    $payment = subscribe([$nadia], amountMinor: 125_000);    // earns 1,000 EGP
    $this->artisan('payouts:process');                       // both paid 1,000 EGP

    subscribe([$sara], amountMinor: 50_000);                 // Sara: +400 EGP outstanding
    app(RefundSubscriptionPayment::class)->handle($payment, RefundType::Full);   // Nadia: 1,000 EGP recoverable

    $this->get(InstructorResource::getUrl('index'))->assertOk();

    Livewire::test(ListInstructors::class)
        ->assertCanSeeTableRecords([$sara, $nadia])
        ->assertSeeInOrder(['Nadia Hassan', 'EGP 0.00', 'EGP 1,000.00', 'EGP 0.00', 'EGP 1,000.00'])
        ->assertSeeInOrder(['Sara Ahmed', 'EGP 1,400.00', 'EGP 1,000.00', 'EGP 400.00', 'EGP 0.00']);
});

it('shows an instructor balance and payout history with date, amount, status and provider reference', function () {
    Queue::fake([ReconcilePayoutJob::class]);
    $instructor = instructorOwed(100_000, ['payout_account_reference' => 'acct_do_not_show']);
    provider()->willReturn(MockOutcome::PermanentFailure);
    $this->artisan('payouts:process');
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess);
    $this->artisan('payouts:process');

    $payouts = $instructor->payouts()->orderBy('id')->get();
    expect($payouts->pluck('status')->all())->toBe([PayoutStatus::Failed, PayoutStatus::Unknown]);

    $this->get(InstructorResource::getUrl('view', ['record' => $instructor]))
        ->assertOk()
        ->assertSee(['Total earned', 'Total paid', 'Outstanding', 'Recoverable', 'EGP 1,000.00'])
        ->assertDontSee('acct_do_not_show');

    Livewire::test(PayoutsRelationManager::class, ['ownerRecord' => $instructor, 'pageClass' => ViewInstructor::class])
        ->assertCanSeeTableRecords($payouts)
        ->assertSee(['Failed', 'Unknown', 'EGP 1,000.00', $payouts->first()->provider_reference, $payouts->first()->created_at->format('M j, Y')])
        ->assertDontSee('acct_do_not_show');
});

it('offers no way to create, edit or delete from the screen', function () {
    $instructor = Instructor::factory()->create();

    expect(InstructorResource::canCreate())->toBeFalse()
        ->and(InstructorResource::canEdit($instructor))->toBeFalse()
        ->and(InstructorResource::canDelete($instructor))->toBeFalse()
        ->and(array_keys(InstructorResource::getPages()))->toBe(['index', 'view']);
});

it('renders the list with a constant number of queries regardless of instructor count', function () {
    Instructor::factory()->count(3)->create();
    DB::enableQueryLog();
    Livewire::test(ListInstructors::class);
    $few = count(DB::getQueryLog());

    Instructor::factory()->count(7)->create();
    DB::flushQueryLog();
    Livewire::test(ListInstructors::class);
    $many = count(DB::getQueryLog());

    expect($many)->toBe($few);
});
