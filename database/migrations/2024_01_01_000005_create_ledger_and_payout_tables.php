<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('weight');
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->unsignedBigInteger('net_cents');
            $table->char('currency', 3)->default('EGP');
            $table->string('idempotency_key');
            $table->timestamp('created_at');

            $table->unique('idempotency_key');
            $table->unique(['subscription_payment_id', 'instructor_id'], 'rev_alloc_payment_instructor_unique');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('EGP');
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->string('idempotency_key');
            $table->string('description')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->unique('idempotency_key');
            $table->index(['instructor_id', 'id']);
            $table->index('payout_id');
            $table->index('type');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EGP');
            $table->string('status');
            $table->string('idempotency_key');
            $table->unsignedBigInteger('through_ledger_entry_id')->nullable();
            $table->string('provider')->default('mock');
            $table->string('provider_reference')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['instructor_id', 'status']);
            $table->index('status');
        });

        Schema::table('instructors', function (Blueprint $table) {
            $table->foreign('in_flight_payout_id')
                ->references('id')
                ->on('payouts')
                ->nullOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign('payout_id')
                ->references('id')
                ->on('payouts')
                ->nullOnDelete();
        });

        Schema::create('mock_provider_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key');
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EGP');
            $table->string('provider_reference');
            $table->string('actual_status');
            $table->string('reported_status');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['payout_id']);
        });

        Schema::table('instructors', function (Blueprint $table) {
            $table->dropForeign(['in_flight_payout_id']);
        });

        Schema::dropIfExists('mock_provider_transfers');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('revenue_allocations');
    }
};
