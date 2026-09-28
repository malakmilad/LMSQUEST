# Architecture: Instructor Revenue Ledger

This is the money core of an LMS: subscription payments come in, instructors get paid out. The standard it is built to is:

> The money must remain correct even when the system behaves incorrectly: jobs run twice, workers crash, the provider times out after paying, refunds land mid-payout.

Everything below follows from that sentence.

---

## 1. Key architectural decisions

| Decision | Why |
|---|---|
| Money is `int` minor units everywhere (`*_minor` columns, `BIGINT`) | Floats cannot represent 0.10; decimals invite float casts in PHP. |
| An append-only **ledger** is the source of truth for what each instructor is owed | Balances can be rebuilt and audited; nothing financial is ever updated or deleted. |
| `instructor_balances` is a **projection** of the ledger, written in the same transaction as each entry | O(1) reads for the admin screen and payout batch without trusting a free-floating column. `ledger:verify` proves they agree. |
| Revenue is recognized **progressively**, one month-period at a time | An annual plan is not fully earned on day one; refunds then follow naturally. |
| The payout ledger entry is written **only when the provider confirms success** | A failed or uncertain transfer never changes the books. |
| A provider timeout is **UNKNOWN**, never FAILED | Declaring failure after the money moved would pay the instructor twice. |
| One deterministic **idempotency key** per payout, reused on every retry | The provider deduplicates; a retry can never become a second transfer. |
| No database transaction is held open during the provider call | Row locks are held for milliseconds, not for an HTTP round trip. |
| Database constraints back every application rule | UNIQUE keys and CHECK constraints still hold if a code path is wrong. |

Layout:

```
app/Actions/Revenue   RecordSubscriptionPayment, AllocateSubscriptionRevenue, RecognizeRevenue, RefundSubscriptionPayment
app/Actions/Payouts   CreateInstructorPayout, ProcessPayouts, ProcessPayout, SubmitPayoutToProvider, ReconcilePayout, CancelPendingPayout
app/Services/Money    Allocator (integer largest-remainder splitting)
app/Services/Ledger   LedgerRecorder (the only ledger writer), LedgerVerifier
app/Services/Payouts  PayoutTransitions (the only payout state changer), PayoutLog
app/Services/Payments MockPaymentProvider, ProviderResult, ProviderStatus, ProviderTimeoutException
app/Contracts         PaymentProvider
app/Jobs              ProcessInstructorPayout, ReconcilePayoutJob
```

---

## 2. Domain model

```
Student ──< Subscription >──< Course >── Instructor
                 │   (subscription_course, optional revenue_share_bps)
                 │
                 └──< SubscriptionPayment ──< RevenueAllocation >── Instructor
                              │                     │ (one per instructor, recognized monthly)
                              └── Refund            │
                                                    ▼
Instructor ──< LedgerEntry  (earning | refund_reversal | payout | adjustment)
Instructor ──1 InstructorBalance  (projection of the ledger)
Instructor ──< Payout  (pending → processing → submitted/unknown → paid | failed | cancelled)
```

- **Subscription**: a student's prepaid term on a plan: `monthly` (1 month), `three_month` (3), `annual` (12). Statuses: active, cancelled, expired, refunded.
- **SubscriptionPayment**: money received. Statuses: pending, succeeded, failed, refunded, partially_refunded. Stores a snapshot of the platform share used at allocation time.
- **RevenueAllocation**: one instructor's share of one payment, with recognition progress (`periods_total`, `periods_recognized`, `next_recognition_at`).
- **LedgerEntry**: an immutable signed amount. Positive credits the instructor, negative debits them.
- **Payout**: one transfer attempt-set to one instructor, identified to the provider by `idempotency_key`.
- `users` are back-office staff only (Filament login). Students and instructors never authenticate here.

---

## 3. Database design

All tables have foreign keys with `RESTRICT` on delete (financial history cannot be orphaned). Key constraints:

| Table | Constraint | Protects against |
|---|---|---|
| `subscription_course` | UNIQUE(subscription_id, course_id) | a course counted twice in a split |
| `subscription_payments` | UNIQUE(provider_reference) | a payment webhook recorded twice |
| `revenue_allocations` | UNIQUE(subscription_payment_id, instructor_id) | allocating a payment twice |
| `refunds` | UNIQUE(subscription_payment_id), UNIQUE(idempotency_key) | refunding a payment twice |
| `ledger_entries` | UNIQUE(entry_key) | any financial event posted twice |
| `payouts` | UNIQUE(idempotency_key) | two payouts sharing a provider key |
| `payouts` | UNIQUE(open_instructor_id) | two open payouts for one instructor |
| `instructor_balances` | UNIQUE(instructor_id) | split projections |
| `mock_provider_transfers` | UNIQUE(idempotency_key) | the mock moving money twice |

`open_instructor_id` equals `instructor_id` while a payout is open and is set to NULL when it becomes final. MySQL has no partial unique index, and a nullable UNIQUE column gives the same "at most one open payout per instructor" guarantee.

**MySQL CHECK constraints** (MySQL 8.0.16+ enforces them):

- ledger sign per type: earning > 0, refund_reversal < 0, payout < 0, adjustment ≠ 0
- payout amount > 0, status in the allowed set, `open_instructor_id` consistent with status
- balances: outstanding ≥ 0, recoverable ≥ 0, never both non-zero
- allocations: `periods_recognized ≤ periods_total`

Indexes follow the read paths: `ledger_entries(instructor_id, occurred_at)` and `(instructor_id, type, occurred_at)` for statements; `payouts(instructor_id, status)` and `(status, created_at)` for the batch and reconcile sweeps; `revenue_allocations(status, next_recognition_at)` for the recognizer; `subscriptions(status, ends_at)` for expiry.

---

## 4. Money and rounding

`App\Services\Money\Allocator` is the only place amounts are split:

- `allocate(amount, weights)`: the **largest remainder** method. Each part gets `floor(amount × w / Σw)`; the leftover units go to the parts with the largest remainders; **ties go to the part listed first**. Callers always order by instructor id (or period number), so the result is deterministic. 100 across three equal shares is **34 / 33 / 33**.
- `percentage(amount, bps)`: `intdiv(amount × bps, 10000)`, i.e. floored.
- `evenly(amount, n)`: `allocate` with equal weights; earlier periods absorb the remainder.

Every function returns parts that sum exactly to the input. A property test in `tests/Unit/AllocatorTest.php` checks 500 random splits.

---

## 5. Revenue allocation strategy

On a succeeded payment (`RecordSubscriptionPayment`, idempotent on `provider_reference`):

1. **Platform share** = `floor(amount × platform_percentage%)`. `platform_percentage` comes from `config/revenue.php` (`REVENUE_PLATFORM_PERCENTAGE`, default 20) and is **snapshotted** on the payment (`platform_share_bps`), so changing the config never changes past payments. Flooring means any sub-unit rounding favours instructors.
2. **Instructor pool** = amount − platform share. Invariant: platform + instructors = payment, exactly.
3. The pool is split across instructors with `Allocator::allocate`, weights ordered by instructor id:
   - If every course on the subscription has `revenue_share_bps` (e.g. 5000/3000/2000), those are the weights. They **must sum to 10000** or allocation is rejected.
   - **Fallback, when no course has a percentage:** equal weight per course. An instructor with 2 of the 4 courses gets half the pool.
   - Some but not all courses having a percentage is ambiguous and rejected (no silent guess).
   - A subscription with no courses: the platform keeps 100% (`platform_share_bps = 10000`), so allocations still sum to the pool (zero).
4. One `revenue_allocations` row per instructor with a non-zero share, created in the same transaction as the payment. Instructor currency must match the payment currency.

Worked example: 1,000 EGP payment, 50/30/20: platform 200, pool 800, instructors 400 / 240 / 160.

---

## 6. Revenue recognition

**Policy:** a subscription term is divided into month-periods (1, 3 or 12). **Period _n_ is earned the moment it starts** (at `starts_at + (n−1) months`). The allocation amount is spread over periods with `Allocator::evenly`.

- Annual: an instructor allocation of 12,000 EGP earns **1,000 EGP per month**. Month 1 is earned on the start date.
- Monthly: fully earned when it starts.
- Starting in the future: nothing earned until the start date.

Mechanics (`RecognizeRevenue`, scheduled hourly as `revenue:recognize`, also run right after a payment is recorded):

- Selects allocations with `status = active AND next_recognition_at <= now` using `chunkById`.
- Locks each allocation, posts one `earning` entry per newly started period with key `earning:allocation:{id}:period:{n}`, dated at the period start, and advances `periods_recognized` / `next_recognition_at`.
- Catches up any number of missed periods in one run; re-running posts nothing new.

Why "earned when the period starts" rather than daily accrual: it matches how access is sold (a month at a time), keeps the ledger at one row per instructor-month instead of one per day, and makes refunds exact (see §12). Recognizing a month at its start rather than its end is a deliberate trade-off: the instructor is paid for a month the student is inside; a refund mid-month does not claw that month back.

---

## 7. Ledger and balance projection

**Ledger (`ledger_entries`)**: append-only. The Eloquent model throws on update and delete; corrections are new entries (`refund_reversal`, `adjustment`). Each entry carries a business `entry_key` (UNIQUE) plus explicit nullable foreign keys to the payment, allocation, refund or payout that caused it.

| Type | Sign | Key |
|---|---|---|
| earning | + | `earning:allocation:{id}:period:{n}` |
| refund_reversal | − | `refund_reversal:refund:{id}:allocation:{id}` |
| payout | − | `payout:{payout_id}` |
| adjustment | ± | chosen by the caller |

**LedgerRecorder** is the only writer. In one transaction it:

1. locks the instructor's `instructor_balances` row `FOR UPDATE` (the per-instructor mutex),
2. returns the existing entry if `entry_key` already exists (and throws if the replay has a different amount or type),
3. validates the sign for the type and the currency,
4. inserts the entry and updates the projection, bumping `version`.

**Projection (`instructor_balances`):**

```
net earned  = earned − reversed + adjusted
position    = net earned − paid
outstanding = max(position, 0)     what we owe the instructor
recoverable = max(−position, 0)    what the instructor owes us
```

`outstanding = net earned − paid` holds whenever the position is non-negative. When it is negative (refund after payout), the shortfall shows as `recoverable` and future earnings pay it back first.

**LedgerVerifier** (`php artisan ledger:verify`, scheduled daily, used in every test) recomputes from scratch and reports drift:

- each projection equals the ledger aggregate,
- platform + pool = payment and Σ allocations = pool for every payment,
- earnings posted per allocation equal the recognized periods,
- each paid payout has exactly one ledger entry equal to −amount; non-paid payouts have none.

---

## 8. Payout lifecycle

```
             claim (row lock, lease)        provider: succeeded
  pending ─────────────────────────► processing ─────────────────────► paid     (ledger −amount, once)
     │                                   │  provider: failed
     │ refund shrank balance             ├───────────────────────────► failed   (no ledger effect)
     ▼                                   │  provider: accepted/pending
  cancelled                              ├───────────────────────────► submitted ─┐
  (never sent)                           │  timeout / any exception               │ reconcile via
                                         └───────────────────────────► unknown ───┤ status(key)
                                                                                  ▼
                                                                    paid | failed | submitted | unknown
                                                                    not_found → resend with the SAME key
```

**Create** (`CreateInstructorPayout`, from `payouts:process`): under the balance row lock, skip if an open payout exists; amount = current `outstanding` (must be ≥ `min_amount_minor`); a payout destination must be on file; key = `payout:{instructor_id}:{n}` from `instructor_balances.payout_sequence`. `open_instructor_id` makes a second open payout impossible at the database level too.

**Process** (`ProcessPayout`, called by the `ProcessInstructorPayout` job):

1. Short transaction: lock the payout row.
   - Final: skip.
   - Live claim (`claimed_until` in the future): **busy**, and the job releases itself.
   - Not pending (processing with an expired claim, submitted, unknown): a previous attempt may have reached the provider, so **reconcile** rather than resend.
   - Pending: lock the balance; if a refund has shrunk `outstanding` below the payout amount, cancel it. Otherwise set `processing`, `attempts + 1`, `claimed_until = now + 300s`, then commit.
2. Call the provider **outside any transaction**.
3. Apply the answer via `PayoutTransitions`: each transition locks the payout row, is a no-op if already final, and for `paid` writes the ledger entry `payout:{id}` in the same transaction.

**Failed is final for that payout.** The balance stays outstanding, and the next `payouts:process` run creates a new payout with a new key. That is safe because the provider definitively said no money moved.

---

## 9. Idempotency approach

Every layer that can be repeated is idempotent on a stable business key:

| Repeated thing | Guard |
|---|---|
| Payment webhook | UNIQUE `provider_reference`; returns the existing payment |
| Allocation | `allocated_at` under a payment row lock + UNIQUE(payment, instructor) |
| Recognition run | UNIQUE `entry_key` per allocation-period; progress under an allocation row lock |
| Refund request | UNIQUE `subscription_payment_id` / `idempotency_key`; returns the existing refund |
| `payouts:process` run twice / concurrently | balance row lock + "open payout exists" check + UNIQUE `open_instructor_id` |
| Same job dispatched twice / retried | payout row lock + claim lease; final payouts are skipped |
| Provider call retried | same `idempotency_key` every time; provider deduplicates |
| Success reported twice (worker + reconciler) | `PayoutTransitions` no-op when final + UNIQUE ledger key `payout:{id}` |

`WithoutOverlapping` job middleware is a courtesy to avoid wasted work; correctness does not depend on it (or on the queue delivering exactly once).

---

## 10. Provider timeout handling and reconciliation

The `PaymentProvider` contract:

```php
public function payout(string $idempotencyKey, string $destination, int $amountMinor, string $currency): ProviderResult;
public function status(string $idempotencyKey): ProviderStatus;   // succeeded | failed | pending | not_found | unknown
```

- `ProviderTimeoutException` **or any other exception** from `payout()` means the payout becomes **unknown**. No ledger entry, no retry with a new key, no second payout (the open-payout guard blocks it).
- `ProcessInstructorPayout` then queues `ReconcilePayoutJob` (delay 60s, retried with backoff for 24h). The scheduled `payouts:reconcile` (every 5 minutes) sweeps unknown/submitted payouts and processing payouts with expired claims, so nothing depends on one job surviving.
- Reconciliation takes a short claim, asks `status(key)` and applies the answer. For **not_found**, the request never reached the provider, so it is resent **with the same key**. Even if the original request is merely slow, the provider deduplicates.
- A worker that crashes after the provider paid but before recording leaves the payout `processing` with a claim. After the lease expires, the next attempt reconciles and finds it succeeded. That is tested by killing the worker inside `markPaid`.

**Mock provider** (`MockPaymentProvider`): deterministic and injectable. It keeps its own `mock_provider_transfers` table (standing in for the provider's database), honours idempotency keys (a replay returns the original result and increments `submission_count`, which tests assert on), and supports: success, permanent failure, timeout after success (stores success, then throws), timeout before receipt (stores nothing, then throws), accepted (settles on the next `status()`), and a degraded status endpoint. Outcomes are scripted in tests, forced from the CLI (`mock-provider:force`), or chosen by destination prefix (`acct_fail_*`, `acct_timeout_*`, `acct_lost_*`, `acct_accept_*`). Random mode exists for demos and throws if used under tests.

---

## 11. Concurrency and transaction boundaries

- **Lock order** is fixed to avoid deadlocks: payment, subscription, allocation, payout, balance. The balance row is always the last lock taken. Multi-instructor refunds lock allocations in id order, which is instructor id order.
- Every money transaction uses `DB::transaction(..., attempts: 3)` so an InnoDB deadlock victim retries.
- The only long operation, the provider call, runs with **no transaction open** and is protected by the claim lease instead. The lease (300s) must exceed the provider HTTP timeout.
- Ledger writes assert they are inside a transaction.

Tests cover this two ways: a deterministic interleaving (the mock's `beforeTransfer` hook runs a second worker and a whole second batch while the first worker is inside the provider call), and a **real multi-process test on MySQL** (`tests/Integration`) where four `payouts:process --sync` processes race over 40 instructors and must produce exactly 40 transfers, each submitted once. The test also asserts that more than one process actually created payouts, so it proves they overlapped.

---

## 12. Refunds and recoverable balances

Refunds never delete or edit anything; they add entries and change statuses. One refund per payment.

**Full refund:** the student gets the whole payment back. Recognition is first caught up to the refund instant. Then every earning recognized for that payment is reversed per allocation (`refund_reversal`), and the remaining periods are cancelled. Payment becomes refunded; subscription becomes refunded.

**Prorated (mid-term) refund:** the student gets back the periods that **have not started**, following the recognition policy. Started periods stay earned by both instructor and platform, so there is nothing to reverse, and unstarted periods are cancelled so they never reach the ledger. The refund amount is the gross value of the unstarted periods, computed from the same per-period splits used for recognition (platform periods + each allocation's periods). So **instructor earned + platform kept + refunded = payment**, exactly, for any amount. Payment becomes partially_refunded; subscription becomes cancelled.

**Refund after payout:** if the instructor was already paid for earnings that are now reversed, their position goes negative and shows as `recoverable` (e.g. 500 EGP). No clawback is attempted from the provider; new earnings offset it first, and `payouts:process` pays nothing while `outstanding` is 0.

**Refund racing a payout:**

- pending payout not yet claimed: cancelled right after the refund commits,
- payout claimed but not sent: the claim step re-checks `outstanding` and cancels,
- payout already at the provider: it completes and the excess becomes recoverable.

---

## 13. Scaling to 500k+ active subscriptions

- **No full-table loads.** Every batch uses `chunkById` on indexed predicates: recognition on `(status, next_recognition_at)`, payouts on `instructor_balances.outstanding_minor`, reconcile on `payouts(status, ...)`. Memory is bounded by the chunk size (500).
- **O(1) balance reads.** The admin screen and payout batch read one projection row per instructor (the list page issues a constant number of queries, and there is a test for it).
- **Ledger growth.** 500k annual subscriptions with ~2 instructors each is roughly 12M earning rows a year. The composite indexes keep per-instructor queries index-only; beyond that, partition `ledger_entries` by `occurred_at` or roll old periods into snapshot entries.
- **Contention** is per instructor (the balance row), not global. Payout processing parallelizes across queue workers because each job only locks its own payout and instructor.
- **Recognition load** spreads naturally across the month, since each allocation's `next_recognition_at` is its own anniversary.
- Scheduled commands use `withoutOverlapping()->onOneServer()`; the scheduler is not what guarantees correctness.

---

## 14. Trade-offs, limitations and the plan-change question

**Trade-offs accepted**

- *Recognition at period start* rather than daily accrual: fewer rows and exact refunds, at the cost of paying for a month the student may leave early.
- *Recoverable balances instead of clawbacks*: we never ask the provider to pull money back; we net it against future earnings. An instructor who never earns again leaves a receivable that needs a human.
- *One open payout per instructor*: an unknown payout blocks further payouts for that instructor until reconciled. That is deliberate: paying more while unsure about the last transfer is how double payments happen.
- *Failed payouts are retried on the next batch with a new key*. A permanently broken account (`acct_fail_*`) fails each run. Production would pause payouts for that destination after N failures and notify the instructor.
- *Single currency per instructor*, and payment currency must match. No FX.
- *SQLite in the default test run*: fast, but it serializes writers. That is why the concurrency and CHECK-constraint tests run on MySQL (see README).

**Known limitations**

- The provider is a mock. A real adapter must map the provider's error taxonomy: only definitive rejections may become `failed`; everything else must surface as an exception (→ unknown).
- Payment ingestion is an action, not an HTTP webhook endpoint.
- Plan changes are not implemented (below).

**Mid-term plan change (discussion only)**

A student upgrades from monthly to annual, or from a 2-course to a 4-course bundle, in the middle of a term:

1. Treat it as **a prorated refund of the old payment plus a new payment**, not an edit. The old payment's started periods stay earned by the old instructors under the old split; its unstarted periods are cancelled and their value becomes a credit.
2. The new payment is recorded with a new `provider_reference` (the student pays the difference, or the credit is applied as a discount line). It gets its own allocation snapshot with the **new** course set and percentages, and its own recognition schedule starting on the change date.
3. Because allocations are snapshots per payment, nothing already in the ledger is rewritten. Instructors who were dropped keep what they earned; new instructors earn only from the change date.
4. If an instructor was already paid for periods that the change cancels (downgrade after payout), the same recoverable mechanism applies.

The pieces this needs already exist (prorated refund, per-payment snapshots, idempotent recording). What is missing is a `PlanChange` record linking the old and new payments, and a credit line type on payments.
