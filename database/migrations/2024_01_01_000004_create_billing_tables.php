<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('interval');
            $table->unsignedInteger('duration_days');
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3)->default('EGP');
            $table->unsignedInteger('instructor_share_bps');
            $table->timestamps();
        });

        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->bigInteger('available_balance_cents')->default(0);
            $table->char('currency', 3)->default('EGP');
            $table->unsignedBigInteger('in_flight_payout_id')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['available_balance_cents', 'in_flight_payout_id']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->timestamps();

            $table->index('instructor_id');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('ends_at');
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique(['subscription_id', 'course_id']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EGP');
            $table->unsignedInteger('instructor_share_bps');
            $table->unsignedBigInteger('instructor_pool_cents');
            $table->unsignedBigInteger('platform_fee_cents');
            $table->string('status');
            $table->string('idempotency_key');
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index('subscription_id');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EGP');
            $table->string('reason')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index('subscription_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('plans');
    }
};
