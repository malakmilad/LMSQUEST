<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('plan', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'ends_at']);
        });

        Schema::create('subscription_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            // Share of the instructor pool for this course, in basis points.
            // Either every row of a subscription has one (summing to 10000) or none do.
            $table->unsignedSmallInteger('revenue_share_bps')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'course_id']);
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('provider_reference')->unique();
            $table->string('status', 20);
            $table->unsignedSmallInteger('platform_share_bps')->nullable();
            $table->unsignedBigInteger('platform_share_minor')->nullable();
            $table->unsignedBigInteger('instructor_pool_minor')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
        });

        Schema::create('revenue_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('share_bps');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('periods_total');
            $table->unsignedTinyInteger('periods_recognized')->default(0);
            $table->timestamp('recognition_starts_at');
            $table->timestamp('next_recognition_at')->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['subscription_payment_id', 'instructor_id']);
            $table->index(['status', 'next_recognition_at']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            // One refund per payment: a second refund of the same prepaid term is a bug.
            $table->foreignId('subscription_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('periods_refunded');
            $table->string('reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamp('refunded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('revenue_allocations');
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_course');
        Schema::dropIfExists('subscriptions');
    }
};
