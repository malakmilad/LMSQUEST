# Architecture — Instructor Revenue Ledger

This is the money core of an LMS, not the LMS. The bar is: instructors are never paid twice, never paid the wrong amount, and the books still close when the payment provider hangs up after moving the money.

## Domain model

```
Student ──< Subscription >── Plan (monthly | quarterly | annual, prepaid)
                │
                ├── Enrollment >── Course >── Instructor
                │
                └── SubscriptionPayment ──< RevenueAllocation (snapshot)
                              │
                              ├── LedgerEntry (append-only)
                              └── Refund ──< LedgerEntry (clawback)

Instructor ──< Payout ──> MockPaymentProvider (external rail)
     │
     └── available_balance_cents  (cached SUM of ledger, same transaction)
```

Answers the three questions at any point in time:

| Question | How |
|---|---|
| How much is each instructor owed? | `InstructorPosition.outstanding()` = available + in-flight holds |
| How much has been paid? | Sum of succeeded payouts (ledger `payout_debit` minus reversals minus in-flight) |
| How much is still outstanding? | Lifetime earned − clawbacks − confirmed paid |

Filament reads this through `InstructorPositionService`. The cached `instructors.available_balance_cents` is what the payout scanner uses so we do not `SUM()` tens of millions of rows every run. Tests assert cache == `SUM(ledger_entries)`.

## When money counts as earned

**Decision:** a successful subscription payment is recognized immediately. Instructors are credited their share on day one of the prepaid term.

**Why not daily accrual?** 500k active subscriptions × 365 days is a write storm, and “owed” becomes a function of wall-clock time that is painful to audit. The cash is already in the platform. Marketplace rails (Stripe Connect, typical course marketplaces) credit on collection and claw back on refund.

**Trade-off:** a refund after payout creates a negative instructor balance instead of “unearned revenue” sitting in a deferred account. That is a collections problem, not a correctness problem: we will not pay them again until the clawback is absorbed. In production I would add a reserve/hold period (e.g. 14 days) before `available` becomes payable, which shrinks the clawback window without changing the ledger shape.

## How a payment is divided

1. Take the payment in **integer minor units** (piastres). No floats.
2. Instructor pool = `floor(amount * instructor_share_bps / 10_000)`. Default 7000 bps (70%). The platform cut is `amount - pool`, so the two sides always sum to the payment.
3. Split the pool across instructors **weighted by enrolled course count at payment time**.
4. Leftover cents: Hamilton / largest-remainder, ties broken by lower `instructor_id`.
5. Persist a `revenue_allocations` snapshot. Later enrollments do not rewrite history.

**Why course count, not watch time?** The quest does not give us engagement data. Course count is observable, deterministic, and explainable. In production I would swap the weight function (watch time, list price, explicit override) without touching the ledger: the snapshot already stores `weight` + `net_cents`.

**No enrollments:** 100% platform. Paying “the catalog” retroactively would create allocations that cannot be audited against a fact that existed at payment time.

## Ledger

`ledger_entries` is append-only. Eloquent `updating` / `deleting` throw. Every row has a unique `idempotency_key`. Duplicate posts return the existing row and **do not** increment the cached balance a second time.

| Type | Sign | Meaning |
|---|---|---|
| `instructor_earning` | + | Share of a collected payment |
| `platform_fee` | + | Platform cut (`instructor_id` null) |
| `refund_clawback` | − | Proportional reversal of the original pool |
| `payout_debit` | − | Hold posted **before** the provider call |
| `payout_reversal` | + | Hold released on permanent provider failure |

The hold-before-call is the important one. If we credited only after `success`, a timeout-after-success would look like “not paid” and a second run would pay again. If we debit after success and the worker dies between HTTP 200 and the DB write, a retry without provider idempotency would pay again. Debiting first, and sending the payout ULID as the provider idempotency key, closes both holes.

## Payout state machine

```
          dispatch
pending ──────────► processing ──► succeeded
                       │
                       ├── failed     (hold reversed, instructor becomes payable again)
                       └── unknown    (hold stays, in_flight stays, status check later)
```

- `payouts:dispatch` selects `available_balance_cents >= min AND in_flight_payout_id IS NULL`.
- Each instructor is `SELECT … FOR UPDATE`.
- One in-flight payout per instructor via `instructors.in_flight_payout_id`.
- Job is `ShouldBeUnique` on payout id (cache). The database unique `payouts.idempotency_key` and the hold row (`payout:{id}:debit`) are the real guarantees — cache uniqueness is a courtesy to the queue.
- Overlapping cron, a manual re-trigger, two app servers: the row lock + in-flight column means the second runner sees nothing to pay.
- Permanent fail: reverse the hold, clear in-flight, next dispatch may try again with a **new** payout id (new provider key). That is a new attempt, not a double-pay of a success.
- Unknown: blocked until `payouts:reconcile` or a job retry. Retry of `transfer()` with the same key: the mock (and a real PSP) returns the **actual** result, not another timeout.

## Provider timeout handling

`MockPaymentProvider` persists the transfer **before** returning.

| Scripted outcome | Stored actual | First HTTP response | Retry / status check |
|---|---|---|---|
| Succeeded | succeeded | succeeded | succeeded |
| Failed | failed | failed | failed |
| Timeout after success | **succeeded** | timeout | succeeded |

That last row is the quest’s “stopped responding after it already moved the money”. The books stay conservative (`unknown` + hold) until we look.

In tests the provider **refuses to be random** — you must `script()`. Randomness is for the seeded demo and for live failure drills.

## Refunds

Refund amount is in cents, `<=` remaining refundable on that payment.

Clawback pool = `floor(original_instructor_pool * refund_amount / original_payment)`. Split with the **snapshotted** weights, not current enrollments.

- Before payout: available drops; dispatch pays nothing (or less).
- After payout: available may go negative. Dispatch requires `available > 0`. The instructor owes the platform; the next earnings repay it.

We do not attempt to reverse the external payout rail. Recovering money already sent is an ops/legal process. The ledger’s job is to stop compounding the error.

Partial / mid-term: the quest does not define a time-proration formula. Using refunded_amount / original_payment is honest about what finance actually keyed into the refund. A 50% goodwill refund mid-annual is 50% of the pool, not “days remaining”. If product wants day-weighted refunds, that belongs in the billing UI that *computes* `amount_cents` before calling `ProcessSubscriptionRefund`.

## Idempotency map

| Operation | Key |
|---|---|
| Collect payment | caller-supplied (`pay-{subscription_id}` in tests) |
| Allocation snapshot | `alloc:payment:{id}:instructor:{id}` |
| Earning post | `earn:payment:{id}:instructor:{id}` |
| Platform fee | `fee:payment:{id}` |
| Refund | caller-supplied |
| Clawback | `clawback:refund:{id}:instructor:{id}` |
| Payout hold | `payout:{id}:debit` |
| Payout reversal | `payout:{id}:reversal` |
| Provider transfer | payout ULID |

## Scaling to 500k subscriptions / tens of millions of rows

What already points that way:

- Integer money, no `DECIMAL` rounding drift
- Append-only ledger (InnoDB insert sequential on `id`, partition candidate on `instructor_id` or month)
- Cached payable balance so dispatch is an index range scan: `(in_flight_payout_id, available_balance_cents, id)` — null check first (eliminates all in-flight rows in one step), range on balance second, `id` as trailing cover column for `chunkById`
- Chunked dispatch (`chunkById`) + `--limit`
- Provider calls outside the instructor row lock (we lock, post hold, commit, then HTTP)
- Jobs unique per payout

What I would add before 500k, not before the review:

- Redis (or DB) lock around dispatch *in addition to* row locks, so two crons do not stampede 500k `FOR UPDATE`s
- Partition `ledger_entries` monthly; keep a `instructor_balance_cents` projection (we already have it)
- Dedicated `payouts` queue with a concurrency cap against the PSP rate limit
- Outbox table if the PSP is replaced by an async webhook
- Read replica for Filament history; primary for dispatch
- Never compute “owed” with a live `SUM` on the hot path — Filament’s per-instructor page is fine to SUM one instructor

## Known limitations

- No reserve period before payable
- No multi-currency
- No tax / withholding
- Mock provider is in *our* database. A real PSP is a different failure domain; the interface is the seam (`PaymentProvider`)
- `ShouldBeUnique` needs a shared cache (Redis) across app servers. The DB constraints do not.
- sqlite in tests does not exercise InnoDB gap locks. Idempotency is still
  enforced by unique keys (`payouts.idempotency_key`, `payout:{id}:debit`
  ledger key). The application-level guard (`in_flight_payout_id` checked
  under `FOR UPDATE`) is validated by a dedicated MySQL harness — see
  [`tests/Concurrency/README.md`](../tests/Concurrency/README.md). Run it
  before shipping changes to `InitiateInstructorPayout` or
  `DispatchInstructorPayouts`, and in CI against a real MySQL service
  container.

## Senior bonus — mid-term plan change (not built)

Example: annual 2999 EGP prepaid, upgrade to a higher annual 60 days in, “fair” adjustment.

I would **not** mutate the original payment or its allocations. That row is history.

1. Compute unused value of the current term: `original_amount * remaining_days / term_days` (or a finance-approved table).
2. Credit that unused value as store credit / against the new plan price (a billing concern).
3. Charge the delta as a **new** `SubscriptionPayment` with its own idempotency key.
4. Allocate the delta with **current** enrollment weights (the student’s mix may have changed). Snapshot it.
5. If the change is a downgrade and we owe the student money, that is a `Refund` against the original payment using the existing clawback path.
6. Subscription row: close the old term (`ends_at = now()`), open a new subscription (or a `subscription_terms` child). Do not splice dates on a paid term that already has allocations.

The invariant we keep: every movement of money is a new immutable document. Fairness is a function that *produces* a cents amount; the ledger only records that amount.

## Why this is smaller than `LARAVEL_ARCHITECTURE.md`

That document is a 10M-user modular monolith. The quest asked for the money core. Repositories wrapping three-line Eloquent calls would hide the locking. Actions + a ledger poster + a provider interface are the seams that matter here. I would extract a `Billing` module (and only that) if this joined a real LMS, not before.
