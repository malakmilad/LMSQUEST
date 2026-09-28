<?php

use App\Actions\Payouts\ProcessPayout;
use App\Actions\Payouts\ReconcilePayout;
use App\Contracts\PaymentProvider;
use App\Enums\LedgerEntryType;
use App\Enums\MockOutcome;
use App\Enums\PayoutAttemptResult;
use App\Enums\PayoutStatus;
use App\Jobs\ReconcilePayoutJob;
use App\Models\LedgerEntry;
use App\Models\MockProviderTransfer;
use App\Models\Payout;
use App\Services\Payments\MockPaymentProvider;
use App\Services\Payments\ProviderResult;
use App\Services\Payouts\PayoutTransitions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Keep the "unknown" state observable; reconciliation is driven explicitly below.
    Queue::fake([ReconcilePayoutJob::class]);
});

function payoutLedgerEntries(): int
{
    return LedgerEntry::query()->where('type', LedgerEntryType::Payout)->count();
}

it('marks a permanently rejected payout failed, leaves the balance outstanding, and pays it with a new payout later', function () {
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::PermanentFailure);

    $this->artisan('payouts:process');

    $failed = Payout::query()->sole();
    expect($failed->status)->toBe(PayoutStatus::Failed)
        ->and($failed->failure_reason)->toBe('destination_account_invalid')
        ->and($failed->open_instructor_id)->toBeNull()
        ->and(payoutLedgerEntries())->toBe(0)
        ->and(balanceOf($instructor)->outstanding_minor)->toBe(100_000);

    $this->artisan('payouts:process');

    $retry = Payout::query()->latest('id')->first();
    expect($retry->id)->not->toBe($failed->id)
        ->and($retry->idempotency_key)->toBe("payout:{$instructor->id}:2")
        ->and($retry->status)->toBe(PayoutStatus::Paid)
        ->and($failed->fresh()->status)->toBe(PayoutStatus::Failed)
        ->and(balanceOf($instructor)->outstanding_minor)->toBe(0);

    expectBooksToBalance();
});

it('treats a timeout after the provider paid as unknown, then reconciles it to paid without a second transfer', function () {
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess);

    $this->artisan('payouts:process');

    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Unknown)
        ->and($payout->status)->not->toBe(PayoutStatus::Failed)
        ->and(payoutLedgerEntries())->toBe(0);

    // A second batch must not resend or open another payout while the first is unresolved.
    $this->artisan('payouts:process');
    expect(Payout::query()->count())->toBe(1)->and(provider()->payoutCalls)->toBe(1);

    $this->artisan('payouts:reconcile')->assertSuccessful();

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->fresh()->provider_reference)->toBe(MockProviderTransfer::query()->sole()->provider_reference)
        ->and(MockProviderTransfer::query()->sole()->submission_count)->toBe(1)
        ->and(payoutLedgerEntries())->toBe(1)
        ->and(balanceOf($instructor)->outstanding_minor)->toBe(0);

    expectBooksToBalance();
});

it('reconciles the same payout any number of times with a single ledger entry', function () {
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess);
    $this->artisan('payouts:process');
    $payout = Payout::query()->sole();

    $this->artisan('payouts:reconcile');
    $this->artisan('payouts:reconcile')->expectsOutput('Nothing to reconcile.');
    expect(app(ReconcilePayout::class)->handle($payout->id))->toBe(PayoutAttemptResult::Skipped);
    app(PayoutTransitions::class)->markPaid($payout->id, 'late-duplicate', 'test');

    expect(payoutLedgerEntries())->toBe(1)
        ->and(balanceOf($instructor)->paid_minor)->toBe(100_000);

    expectBooksToBalance();
});

it('resends with the same idempotency key when the request never reached the provider', function () {
    $instructor = instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutBeforeReceipt);

    $this->artisan('payouts:process');
    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Unknown)->and(MockProviderTransfer::query()->count())->toBe(0);

    $this->artisan('payouts:reconcile');

    $payout->refresh();
    expect($payout->status)->toBe(PayoutStatus::Paid)
        ->and($payout->attempts)->toBe(2)
        ->and(MockProviderTransfer::query()->sole()->idempotency_key)->toBe($payout->idempotency_key)
        ->and(payoutLedgerEntries())->toBe(1);

    expectBooksToBalance();
});

it('keeps a payout unknown while the provider cannot say what happened', function () {
    instructorOwed(100_000);
    provider()->willReturn(MockOutcome::TimeoutAfterSuccess)->statusUnavailable(2);
    $this->artisan('payouts:process');

    $this->artisan('payouts:reconcile');
    $this->artisan('payouts:reconcile');

    expect(Payout::query()->sole()->status)->toBe(PayoutStatus::Unknown)
        ->and(payoutLedgerEntries())->toBe(0)
        ->and(provider()->payoutCalls)->toBe(1);

    $this->artisan('payouts:reconcile');
    expect(Payout::query()->sole()->status)->toBe(PayoutStatus::Paid);
});

it('tracks a transfer the provider accepted but has not settled until it settles', function () {
    instructorOwed(100_000);
    provider()->willReturn(MockOutcome::Accepted);

    $this->artisan('payouts:process');
    $payout = Payout::query()->sole();
    expect($payout->status)->toBe(PayoutStatus::Submitted)
        ->and($payout->provider_reference)->not->toBeNull()
        ->and(payoutLedgerEntries())->toBe(0);

    $this->artisan('payouts:reconcile');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)->and(payoutLedgerEntries())->toBe(1);
});

it('treats any unexpected provider exception as unknown, never as failed', function () {
    instructorOwed(100_000);
    $this->app->instance(PaymentProvider::class, new class extends MockPaymentProvider
    {
        public function payout(string $idempotencyKey, string $destination, int $amountMinor, string $currency): ProviderResult
        {
            throw new RuntimeException('connection reset by peer');
        }
    });

    $this->artisan('payouts:process');

    expect(Payout::query()->sole()->status)->toBe(PayoutStatus::Unknown)
        ->and(Payout::query()->sole()->failure_reason)->toContain('connection reset by peer');
});

it('does not let a failure report undo a payout that is already paid', function () {
    $instructor = instructorOwed(100_000);
    $this->artisan('payouts:process');
    $payout = Payout::query()->sole();

    app(PayoutTransitions::class)->markFailed($payout->id, null, 'late failure webhook', 'test');
    app(PayoutTransitions::class)->markUnknown($payout->id, 'late timeout', 'test');

    expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
        ->and(balanceOf($instructor)->paid_minor)->toBe(100_000);
});

it('skips a payout that is already final when processed again', function () {
    instructorOwed(100_000);
    $this->artisan('payouts:process');

    expect(app(ProcessPayout::class)->handle(Payout::query()->sole()->id))->toBe(PayoutAttemptResult::Skipped)
        ->and(provider()->payoutCalls)->toBe(1);
});
