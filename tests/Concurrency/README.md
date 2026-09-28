# MySQL Concurrency Harness

## Why this exists

The regular test suite runs on SQLite (`DB_CONNECTION=sqlite`). SQLite serialises
all writes at the file level, so it cannot reproduce the InnoDB row-lock behaviour
that prevents a double-dispatch in production. This is acknowledged in
[docs/ARCHITECTURE.md](../../docs/ARCHITECTURE.md) under *Known limitations*.

This harness fills that gap by running the real payout-dispatch path against a
MySQL database with several PHP processes in parallel.

## What it verifies

**Invariant:** no matter how many processes call `payouts:dispatch` at the same
moment, exactly **one** `Payout` row and exactly **one** `MockProviderTransfer`
row are created for a given instructor.

The locking path under test is:

```
InitiateInstructorPayout::handle()
  └─ DB::transaction()
       └─ SELECT … FROM instructors WHERE … FOR UPDATE   ← InnoDB row lock
            ├─ if in_flight_payout_id IS NOT NULL → return null  (loser path)
            └─ create Payout, post ledger debit, set in_flight_payout_id  (winner path)
```

The second runner to reach `FOR UPDATE` blocks until the first commits, then
sees `in_flight_payout_id ≠ NULL` and exits early.  The
`payouts.idempotency_key` unique constraint and the `payout:{id}:debit` ledger
idempotency key are a second line of defence if the application-level guard
ever regresses.

## Quick start

### 1. Create a throwaway MySQL database

```sql
CREATE DATABASE lms_concurrency CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Create `.env.concurrency`

```bash
cp .env.example .env.concurrency
```

Edit the copy:

```dotenv
APP_ENV=concurrency
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lms_concurrency
DB_USERNAME=root
DB_PASSWORD=

# Must be sync so each worker runs the job inside its own process (no queue worker needed)
QUEUE_CONNECTION=sync

# Standard revenue config — must match what the application expects
REVENUE_MIN_PAYOUT_CENTS=0
```

### 3. Run the harness

```bash
bash tests/Concurrency/mysql_concurrency_harness.sh
```

Expected output (5 workers, default):

```
  [harness] Using env file: …/.env.concurrency
  [harness] Migrating fresh …
  [harness] Seeding test data …
  [harness] Launching 5 concurrent payouts:dispatch processes …
  [harness] Waiting for all workers …
  [harness] Worker logs:
    …
  [harness] Querying results …

  Results:
    payouts created      : 1   (expected 1)
    provider transfers   : 1   (expected 1)
    succeeded payouts    : 1   (expected 1)

  ✓ exactly 1 payout created
  ✓ exactly 1 provider transfer recorded
  ✓ payout status is succeeded

  HARNESS PASSED — InnoDB row-lock concurrency guarantee holds with 5 concurrent dispatchers.
```

### Options

| Variable     | Default           | Description                                              |
|--------------|-------------------|----------------------------------------------------------|
| `ENV_FILE`   | `.env.concurrency`| Laravel env file to use                                  |
| `CONCURRENCY`| `5`               | Number of parallel `payouts:dispatch` processes          |
| `PRICE_CENTS`| `10000`           | Subscription price in cents                              |
| `SHARE_BPS`  | `7000`            | Instructor share in basis points                         |
| `KEEP_DB`    | `0`               | Set to `1` to skip `migrate:fresh` (reuse existing data) |
| `PHP`        | `php`             | PHP binary path                                          |

```bash
# 10 concurrent workers, keep existing database
CONCURRENCY=10 KEEP_DB=1 bash tests/Concurrency/mysql_concurrency_harness.sh
```

## When to run it

- Before shipping changes to `InitiateInstructorPayout`, `DispatchInstructorPayouts`, or any code that touches `instructors.in_flight_payout_id`.
- After upgrading MySQL/MariaDB major versions.
- As part of a pre-production smoke test on the target database server.

It is intentionally **not** part of the normal `php artisan test` run because it
requires an external MySQL instance and takes ~10 seconds for 5 workers.  Add it
to your CI pipeline as a separate job that provisions a MySQL service container:

```yaml
# Example GitHub Actions step
- name: MySQL concurrency harness
  env:
    ENV_FILE: .env.concurrency
  run: bash tests/Concurrency/mysql_concurrency_harness.sh
```

## Failure modes and what they mean

| Symptom | Likely cause |
|---------|-------------|
| `payouts = 2` | The `FOR UPDATE` lock or the `in_flight_payout_id` guard regressed |
| `transfers = 2` | Provider idempotency key is not being forwarded correctly |
| Worker exits non-zero | Migration failed, `.env.concurrency` misconfigured, or provider scripting failed |
| `payouts = 0` | Seed data missing, `available_balance_cents = 0`, or all workers saw an exception |
