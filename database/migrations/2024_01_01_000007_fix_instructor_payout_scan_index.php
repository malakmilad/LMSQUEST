<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The dispatch query in DispatchInstructorPayouts::handle() is:
     *
     *   WHERE in_flight_payout_id IS NULL
     *     AND available_balance_cents >= :min
     *     AND available_balance_cents > 0
     *   ORDER BY id
     *
     * The old index (available_balance_cents, in_flight_payout_id) put the
     * range column first, forcing MySQL to scan the full balance range before
     * filtering on in_flight_payout_id — the opposite of what we want.
     *
     * The new index (in_flight_payout_id, available_balance_cents, id):
     *
     *   1. in_flight_payout_id  — equality / IS NULL check, highest selectivity.
     *      On a healthy system most instructors have no in-flight payout, so
     *      the NULL partition is the large one; we still want this first because
     *      it eliminates every in-flight row in one step.
     *
     *   2. available_balance_cents — range predicate comes immediately after
     *      the equality column, which is the only position MySQL can use a
     *      range condition inside a composite index.
     *
     *   3. id — covering column for the chunkById ORDER BY / WHERE id > ?
     *      continuation clause; avoids a filesort or a second index lookup.
     *
     * Run EXPLAIN on the dispatch query after deploying to confirm
     * "Using index condition" (or "Using where; Using index") with
     * type = range and key = instructors_dispatch_scan_index.
     */
    public function up(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            // Drop the old incorrectly-ordered composite index.
            $table->dropIndex(['available_balance_cents', 'in_flight_payout_id']);

            // Add the correctly-ordered index with id as a trailing cover column.
            $table->index(
                ['in_flight_payout_id', 'available_balance_cents', 'id'],
                'instructors_dispatch_scan_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('instructors', function (Blueprint $table) {
            $table->dropIndex('instructors_dispatch_scan_index');

            $table->index(
                ['available_balance_cents', 'in_flight_payout_id'],
                'instructors_available_balance_cents_in_flight_payout_id_index'
            );
        });
    }
};
