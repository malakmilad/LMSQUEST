#!/usr/bin/env bash
# =============================================================================
# MySQL concurrency harness — payout double-dispatch test
# =============================================================================
#
# PURPOSE
# -------
# Verify that two processes calling `payouts:dispatch` at the *same moment*
# against a real MySQL database produce exactly one Payout row and one
# MockProviderTransfer row, not two.
#
# SQLite (used in the normal test suite) serialises all writes and does not
# reproduce InnoDB row-level locking. This script exercises the actual
# locking path that `InitiateInstructorPayout` relies on:
#
#   SELECT … FROM instructors … FOR UPDATE
#   (create payout, post ledger debit, set in_flight_payout_id)   ← commit
#   → second runner picks up the same instructor row inside its own
#     transaction, sees in_flight_payout_id ≠ NULL, and returns null
#
# REQUIREMENTS
# ------------
#   • MySQL 8+ or MariaDB 10.6+ reachable with the credentials in .env
#   • PHP CLI on PATH
#   • The application's .env must NOT be the test environment — set
#     DB_DATABASE to a throwaway schema created solely for this harness.
#     Example .env.concurrency (copy from .env.example and fill in):
#
#       DB_CONNECTION=mysql
#       DB_HOST=127.0.0.1
#       DB_PORT=3306
#       DB_DATABASE=lms_concurrency
#       DB_USERNAME=root
#       DB_PASSWORD=
#       QUEUE_CONNECTION=sync
#       MOCK_PROVIDER_OUTCOME=succeeded   # see note below
#
# USAGE
# -----
#   bash tests/Concurrency/mysql_concurrency_harness.sh
#
# Or with a custom env file:
#   ENV_FILE=.env.concurrency bash tests/Concurrency/mysql_concurrency_harness.sh
#
# OPTIONS (env vars)
# ------------------
#   ENV_FILE          Path to the Laravel .env to use          (default: .env.concurrency)
#   CONCURRENCY       Number of parallel dispatch processes     (default: 5)
#   PRICE_CENTS       Subscription price for the test payment   (default: 10000)
#   SHARE_BPS         Instructor share in basis points          (default: 7000)
#   KEEP_DB           Set to 1 to skip the drop-and-recreate    (default: 0)
#   PHP               PHP binary to use                         (default: php)
#
# HOW IT WORKS
# ------------
#   1. Fresh-migrate the concurrency database (drops all tables first).
#   2. Seed one instructor with a paid subscription via a small Artisan
#      tinker script so there is exactly one instructor with a positive
#      available_balance_cents.
#   3. Launch $CONCURRENCY PHP processes all running `payouts:dispatch`
#      at the same time via Bash background jobs.
#   4. Wait for all processes to finish, collecting exit codes.
#   5. Query the database directly to count payouts and provider transfers.
#   6. Assert counts are both exactly 1.  Exit 0 on pass, 1 on fail.
#
# PROVIDER NOTE
# -------------
# The MockPaymentProvider in test mode requires a scripted outcome. The seed
# script below injects one "succeeded" script entry into the singleton before
# calling payouts:dispatch. Because the singleton is per-process, only the
# first process to actually initiate a payout will consume a script entry —
# the rest will find in_flight_payout_id already set and return null before
# the provider is called. This is the correct behaviour to assert.
#
# =============================================================================

set -euo pipefail

ENV_FILE="${ENV_FILE:-.env.concurrency}"
CONCURRENCY="${CONCURRENCY:-5}"
PRICE_CENTS="${PRICE_CENTS:-10000}"
SHARE_BPS="${SHARE_BPS:-7000}"
KEEP_DB="${KEEP_DB:-0}"
PHP="${PHP:-php}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# ── helpers ──────────────────────────────────────────────────────────────────

info()  { echo "  [harness] $*"; }
pass()  { echo "  ✓ $*"; }
fail()  { echo "  ✗ $*"; exit 1; }

artisan() { "$PHP" "$ROOT/artisan" --env="${ENV_FILE}" "$@"; }

# ── sanity checks ─────────────────────────────────────────────────────────────

if [[ ! -f "$ROOT/$ENV_FILE" ]]; then
    cat >&2 <<EOF

ERROR: $ROOT/$ENV_FILE not found.

Create it from .env.example and point DB_DATABASE at a throwaway MySQL schema
(e.g. lms_concurrency). Do NOT use your development or production database.

EOF
    exit 1
fi

if ! "$PHP" -r 'echo PHP_VERSION;' &>/dev/null; then
    fail "PHP binary not found: $PHP"
fi

# ── database setup ───────────────────────────────────────────────────────────

info "Using env file: $ROOT/$ENV_FILE"

if [[ "$KEEP_DB" -ne 1 ]]; then
    info "Migrating fresh (--seed skipped — seeding manually below) …"
    artisan migrate:fresh --force 2>&1 | sed 's/^/    /'
else
    info "KEEP_DB=1 — skipping migrate:fresh"
fi

# ── seed one instructor with earnings ────────────────────────────────────────

info "Seeding test data …"

SEED_SCRIPT=$(cat <<'TINKER'
use App\Models\{Plan, User, Subscription, Course, Enrollment, Instructor};
use App\Enums\{PlanInterval, SubscriptionStatus};
use App\Actions\RecordSubscriptionPayment;

$plan = Plan::factory()->create([
    'price_cents'          => (int) env('HARNESS_PRICE_CENTS', 10000),
    'instructor_share_bps' => (int) env('HARNESS_SHARE_BPS',   7000),
]);

$student    = User::factory()->student()->create();
$instructor = Instructor::factory()->create();
$course     = Course::factory()->for($instructor)->create();

$subscription = Subscription::factory()->for($student)->for($plan)->create([
    'status'  => SubscriptionStatus::Active,
    'ends_at' => now()->addDays($plan->duration_days),
]);

Enrollment::factory()->for($subscription)->for($course)->create();

app(RecordSubscriptionPayment::class)->handle($subscription, 'harness-pay-1');

echo "Instructor ID: {$instructor->id}\n";
echo "Available balance: {$instructor->fresh()->available_balance_cents}\n";
TINKER
)

HARNESS_PRICE_CENTS="$PRICE_CENTS" HARNESS_SHARE_BPS="$SHARE_BPS" \
    artisan tinker --execute="$SEED_SCRIPT" 2>&1 | sed 's/^/    /'

# ── parallel dispatch ─────────────────────────────────────────────────────────

info "Launching $CONCURRENCY concurrent payouts:dispatch processes …"

PIDS=()
TMPDIR_LOGS="$(mktemp -d)"

# Each worker scripts the MockPaymentProvider with a Succeeded outcome.
# Workers that find no work to do (in_flight already set) will not call
# the provider at all, so scripting each with Succeeded is safe.
WORKER_SCRIPT=$(cat <<'TINKER'
app(\App\Payments\MockPaymentProvider::class)->script(
    \App\Enums\ProviderReportedStatus::Succeeded
);
TINKER
)

for i in $(seq 1 "$CONCURRENCY"); do
    LOG="$TMPDIR_LOGS/worker_$i.log"
    (
        # Inject a scripted provider outcome via bootstrap, then dispatch.
        # We use tinker for the provider script because artisan commands do
        # not expose the service container singleton directly from argv.
        "$PHP" "$ROOT/artisan" --env="$ENV_FILE" tinker \
            --execute="$WORKER_SCRIPT; \Artisan::call('payouts:dispatch', ['--min' => 0]);" \
            >"$LOG" 2>&1
        echo $? >>"$LOG"
    ) &
    PIDS+=($!)
done

info "Waiting for all workers …"
ALL_OK=1
for pid in "${PIDS[@]}"; do
    if ! wait "$pid"; then
        ALL_OK=0
    fi
done

info "Worker logs:"
for i in $(seq 1 "$CONCURRENCY"); do
    echo "    --- worker $i ---"
    cat "$TMPDIR_LOGS/worker_$i.log" | sed 's/^/    /'
done

rm -rf "$TMPDIR_LOGS"

if [[ "$ALL_OK" -ne 1 ]]; then
    fail "One or more worker processes exited with a non-zero status (see logs above)."
fi

# ── assertions ────────────────────────────────────────────────────────────────

info "Querying results …"

ASSERT_SCRIPT=$(cat <<'TINKER'
use App\Models\{Payout, MockProviderTransfer};

$payouts    = Payout::query()->count();
$transfers  = MockProviderTransfer::query()->count();
$succeeded  = Payout::query()->where('status', 'succeeded')->count();

echo "payouts={$payouts} transfers={$transfers} succeeded={$succeeded}\n";
TINKER
)

RESULT=$(artisan tinker --execute="$ASSERT_SCRIPT" 2>/dev/null | grep -E '^payouts=')

PAYOUTS=$(echo   "$RESULT" | grep -oP 'payouts=\K\d+')
TRANSFERS=$(echo "$RESULT" | grep -oP 'transfers=\K\d+')
SUCCEEDED=$(echo "$RESULT" | grep -oP 'succeeded=\K\d+')

echo ""
echo "  Results:"
echo "    payouts created      : $PAYOUTS   (expected 1)"
echo "    provider transfers   : $TRANSFERS (expected 1)"
echo "    succeeded payouts    : $SUCCEEDED (expected 1)"
echo ""

FAILED=0

if [[ "$PAYOUTS" -ne 1 ]]; then
    echo "  ✗ FAIL: expected 1 payout, got $PAYOUTS"
    FAILED=1
else
    pass "exactly 1 payout created"
fi

if [[ "$TRANSFERS" -ne 1 ]]; then
    echo "  ✗ FAIL: expected 1 provider transfer, got $TRANSFERS"
    FAILED=1
else
    pass "exactly 1 provider transfer recorded"
fi

if [[ "$SUCCEEDED" -ne 1 ]]; then
    echo "  ✗ FAIL: expected 1 succeeded payout, got $SUCCEEDED"
    FAILED=1
else
    pass "payout status is succeeded"
fi

if [[ "$FAILED" -ne 0 ]]; then
    echo ""
    echo "  HARNESS FAILED — InnoDB row-lock concurrency guarantee is broken."
    exit 1
fi

echo ""
echo "  HARNESS PASSED — InnoDB row-lock concurrency guarantee holds with $CONCURRENCY concurrent dispatchers."
exit 0
