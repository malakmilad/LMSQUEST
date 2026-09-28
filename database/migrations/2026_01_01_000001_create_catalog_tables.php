<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('instructors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('payout_account_reference')->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('instructor_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('students');
    }
};
