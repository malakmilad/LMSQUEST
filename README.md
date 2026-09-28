# Instructor Revenue Ledger

The money core of an LMS: subscription payments come in, instructors get paid out. Built for the Career 180 Full Stack Laravel hiring quest.

It is not a full LMS. It is the financial kernel:

- integer-only revenue allocation,
- progressive revenue recognition,
- an append-only ledger with a balance projection,
- refunds,
- an idempotent payout pipeline that survives double runs, job retries, crashed workers and a provider that times out after paying,
- a read-only Filament screen.

Design reasoning is in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). How AI was used is in [`docs/AI_USAGE.md`](docs/AI_USAGE.md).

## Stack

Laravel 11, Livewire 3 (via Filament), Filament 3, Pest 3, MySQL 8.4. PHP 8.2+.

## Install

```bash
composer install
cp .env.example .env
php artisan key:generate

docker compose up -d          # MySQL 8.4 on host port 3307 (databases: lms, lms_testing)
php artisan migrate:fresh --seed
```

`.env.example` already points at the compose database (`127.0.0.1:3307`, user `lms`, password `secret`). To use your own MySQL, change the `DB_*` values.

Admin screen: run `php artisan serve --port=8001`, then open `http://127.0.0.1:8001/admin` and sign in as `admin@lms.test` / `password`. It shows **Instructor balances**: per instructor, Total earned / Total paid / Outstanding / Recoverable, and on the detail page the payout history (date, amount, status, provider reference) and the ledger.

### What the seed data shows

| Instructor | State |
|---|---|
| Sara, Omar | paid, then new earnings outstanding. Omar also has a prorated annual refund, and Sara, Omar and Layla share a 50/30/20 annual bundle |
| Layla | payout **failed** (provider rejects `acct_fail_*`); balance still outstanding |
| Karim | payout **unknown** (provider paid, then timed out); run `php artisan payouts:reconcile` to resolve it to paid |
| Nadia | paid, then a full refund: **recoverable** 240 EGP |

## Queue and payout commands

```bash
php artisan payouts:process          # create payouts for everyone owed >= the minimum, queue one job each
php artisan queue:work               # send them (QUEUE_CONNECTION=database)
php artisan payouts:reconcile        # resolve unknown/submitted payouts via provider status()
```

`payouts:process --sync` processes each payout in the command's own process instead of queueing it.

| Command | Purpose |
|---|---|
| `payouts:process [--sync]` | Batch: create payouts (chunked), queue `ProcessInstructorPayout`, resume stalled payouts |
| `payouts:reconcile` | Ask the provider about every uncertain payout and apply the answer (idempotent) |
| `revenue:recognize` | Post earnings for subscription months that have started; mark ended subscriptions expired |
| `subscriptions:refund {payment} [--prorated] [--reason=]` | Full (default) or prorated refund |
| `ledger:show {instructor}` | Print an instructor's ledger and balance |
| `ledger:verify` | Rebuild every balance from the ledger and check all invariants |
| `mock-provider:force {outcome} [--times=N] [--clear]` | Force the next mock transfer outcome (for demos) |

Scheduled in `routes/console.php`: recognize hourly, process daily, reconcile every 5 minutes, verify daily. All use `withoutOverlapping()->onOneServer()`. Run `php artisan schedule:work` locally.

### Demo the failure modes

```bash
php artisan mock-provider:force timeout_after_success
php artisan payouts:process --sync     # payout goes UNKNOWN; no ledger entry, no second payout possible
php artisan payouts:process --sync     # does nothing for that instructor: the payout is still open
php artisan payouts:reconcile          # provider says it succeeded: PAID, one ledger entry, one transfer
php artisan ledger:verify
```

Other outcomes: `permanent_failure`, `timeout_before_receipt` (reconcile finds nothing and resends with the same key), `accepted` (submitted, settles on reconcile).

## Tests

```bash
php artisan test                      # SQLite in memory (phpunit.xml), ~40 s
```

Full run on MySQL. This adds the CHECK-constraint test and the real multi-process concurrency test, which SQLite skips:

```powershell
# PowerShell
$env:DB_CONNECTION='mysql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='3307'; $env:DB_DATABASE='lms_testing'; $env:DB_USERNAME='lms'; $env:DB_PASSWORD='secret'
php vendor/bin/pest
```

```bash
# bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=lms_testing DB_USERNAME=lms DB_PASSWORD=secret php vendor/bin/pest
```

Captured runs: [`docs/evidence/test-run.txt`](docs/evidence/test-run.txt).

| Required scenario | Test |
|---|---|
| Allocation 50/30/20 | `Feature/Revenue/RevenueAllocationTest`: *allocates a payment 50/30/20…* |
| Rounding 100 → 34/33/33 | `Unit/AllocatorTest`, `Feature/Revenue/RoundingTest` |
| `payouts:process` twice | `Feature/Payouts/PayoutProcessTest`: *pays an instructor exactly once when payouts:process runs twice* |
| Duplicate job | `Feature/Payouts/PayoutJobTest`: *sends one transfer when the same payout job is dispatched twice* |
| Job retry after crash | `PayoutJobTest`: *retries a job that crashed after the provider paid, without paying twice* |
| Permanent failure | `Feature/Payouts/ProviderFailureTest`: *marks a permanently rejected payout failed…* |
| Timeout after success, then unknown, reconcile, paid | `ProviderFailureTest`: *treats a timeout after the provider paid as unknown, then reconciles…* |
| Reconcile idempotent | `ProviderFailureTest`: *reconciles the same payout any number of times with a single ledger entry* |
| Refund reversal | `Feature/Refunds/RefundTest`: *reverses recognized earnings with new ledger entries…* |
| Refund after payout, recoverable 500 | `RefundTest`: *turns a refund after payout into a recoverable 500 EGP…* |
| Concurrent payout | `PayoutProcessTest`: *lets only one worker send a payout…* and `Integration/ConcurrentPayoutProcessesTest` (4 OS processes, MySQL) |

Every money-moving test ends with `expectBooksToBalance()`, which runs the same checks as `ledger:verify`.

## Assumptions

| Question | Decision |
|---|---|
| Money representation | Integer minor units (piastres); `BIGINT` columns; no floats anywhere |
| Platform share | `REVENUE_PLATFORM_PERCENTAGE` (default 20), floored, snapshotted per payment |
| Instructor split | Course `revenue_share_bps` on `subscription_course` (must total 100%). **Fallback when none are set: equal share per course.** Partial percentages are rejected |
| Rounding | Largest remainder; ties go to the lowest instructor id (or earliest month) |
| When is revenue earned? | Progressively: one month-period at a time, earned when that month starts (annual 12,000 gives 1,000 per month) |
| Mid-term refund | Prorated: unstarted months are refunded and never earned; started months stay earned. Full refund reverses everything recognized |
| Refund after payout | Negative position becomes **recoverable**, offset against future earnings; no provider clawback |
| Minimum payout | `PAYOUT_MIN_AMOUNT_MINOR` (default 10,000 = 100 EGP) |
| Currency | One currency per instructor; must match the payment |
| Admin users | `users` are staff; everyone in `users` can open the read-only screen |

## Failure handling

| What goes wrong | What happens |
|---|---|
| `payouts:process` runs twice or concurrently | Balance row lock + UNIQUE open-payout guard: one payout per instructor |
| Job delivered twice, or retried | Payout row claim; final payouts skipped; provider sees the same idempotency key |
| Worker dies mid-payout | Claim lease expires, then the next attempt **reconciles** via `status()` instead of resending |
| Provider rejects | `failed`, no ledger effect, balance stays outstanding, and the next batch creates a new payout |
| Provider times out (or any exception) | `unknown`, never `failed`; no ledger effect; blocks new payouts for that instructor; reconciled by job + schedule |
| Request never reached provider | reconcile gets `not_found` and resends with the **same** key |
| Provider status endpoint down | stays `unknown`, rechecked later |
| Success reported twice | Transition no-op + UNIQUE ledger key `payout:{id}` |
| Refund while payout pending | pending payout cancelled; one already sent leaves a recoverable balance |
| Projection drift | `ledger:verify` reports it (scheduled daily) |

Payout logs are JSON lines in `storage/logs/payouts-*.log`. Each line carries payout id, instructor id, amount, currency, status, idempotency key, provider reference and attempts. Destination accounts are never logged (a test checks this).

## Limitations

- The payment provider is a mock (`App\Services\Payments\MockPaymentProvider`, bound to `App\Contracts\PaymentProvider`). A real adapter must turn only definitive rejections into `failed`.
- Payments are recorded through an action (`RecordSubscriptionPayment`), not an HTTP webhook.
- A destination that always rejects is retried each batch with a new payout; production would pause it after N failures.
- Plan changes mid-term are discussed in ARCHITECTURE §14, not built.
- Recoverable balances from instructors who never earn again need manual collection.
