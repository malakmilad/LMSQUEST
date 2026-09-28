<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            // Stamped when a worker picks up the payout and calls the provider.
            // Used by payouts:recover-stale to detect payouts that have been in
            // pending/processing for longer than the configured staleness threshold
            // without reaching a terminal or unknown state.
            $table->timestamp('processing_started_at')->nullable()->after('dispatched_at');

            // Index lets the recovery command cheaply find stale rows without a
            // full table scan: filter on status first, then range on timestamp.
            $table->index(['status', 'processing_started_at'], 'payouts_stale_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex('payouts_stale_recovery_index');
            $table->dropColumn('processing_started_at');
        });
    }
};
