<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->string('idempotency_key')->unique();
            $table->string('destination');
            $table->string('provider_reference')->nullable()->index();
            $table->string('provider_status', 20)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            // Equals instructor_id while the payout is open, NULL once final.
            // UNIQUE => at most one open payout per instructor, enforced by the database.
            $table->unsignedBigInteger('open_instructor_id')->nullable()->unique();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamps();

            $table->index(['instructor_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            // Signed: positive credits the instructor, negative debits them.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // Business reference of the event; UNIQUE makes every financial effect happen once.
            $table->string('entry_key')->unique();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('revenue_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['instructor_id', 'occurred_at']);
            $table->index(['instructor_id', 'type', 'occurred_at']);
        });

        Schema::create('instructor_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('earned_minor')->default(0);
            $table->bigInteger('reversed_minor')->default(0);
            $table->bigInteger('adjusted_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('outstanding_minor')->default(0);
            $table->bigInteger('recoverable_minor')->default(0);
            $table->unsignedInteger('payout_sequence')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });

        // Stand-in for the provider's own database. Lives here only because the provider is mocked.
        Schema::create('mock_provider_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->string('provider_reference')->unique();
            $table->string('destination');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20);
            $table->unsignedInteger('submission_count')->default(1);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_sign_by_type CHECK (
                (type = 'earning' AND amount_minor > 0)
                OR (type = 'refund_reversal' AND amount_minor < 0)
                OR (type = 'payout' AND amount_minor < 0)
                OR (type = 'adjustment' AND amount_minor <> 0)
            )");

            DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_valid CHECK (
                status IN ('pending', 'processing', 'submitted', 'unknown', 'paid', 'failed', 'cancelled')
            )");
            DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive CHECK (amount_minor > 0)');
            DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_open_guard_matches_status CHECK (
                (status IN ('pending', 'processing', 'submitted', 'unknown') AND open_instructor_id = instructor_id)
                OR (status IN ('paid', 'failed', 'cancelled') AND open_instructor_id IS NULL)
            )");

            DB::statement('ALTER TABLE instructor_balances ADD CONSTRAINT balances_non_negative CHECK (
                outstanding_minor >= 0 AND recoverable_minor >= 0 AND (outstanding_minor = 0 OR recoverable_minor = 0)
            )');

            DB::statement('ALTER TABLE revenue_allocations ADD CONSTRAINT allocations_periods_valid CHECK (
                periods_recognized <= periods_total
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_transfers');
        Schema::dropIfExists('instructor_balances');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payouts');
    }
};
