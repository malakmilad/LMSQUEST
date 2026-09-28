# Instructor Revenue Ledger

Money core for an LMS: subscription payments in, instructor payouts out. Built for the Career 180 Full Stack Laravel hiring quest.

This is not a full LMS. It is the financial kernel: allocation, an append-only ledger, an idempotent payout pipeline, and a mock payment provider that lies.

## Stack

- Laravel 11
- Livewire v3 / Alpine.js (via Filament)
- Filament v3
- Pest 3
- MySQL in production, SQLite for local demo and tests

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

SQLite (default):

```bash
# Windows PowerShell
New-Item -ItemType File -Path database/database.sqlite -Force
php artisan migrate --seed
```

MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lms
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate --seed
```

Run the app (Laragon vhost or):

```bash
php artisan serve
```

Admin UI: [http://localhost:8000/admin](http://localhost:8000/admin)

| Email | Password |
|---|---|
| `admin@lms.test` | `password` |

Payouts (after seed some are already in history):

```bash
php artisan payouts:dispatch --min=0
php artisan payouts:reconcile
php artisan queue:work
```

`--min` is cents. Production default is `10000` (100.00 EGP) via `REVENUE_MIN_PAYOUT_CENTS`.

## Tests

```bash
php artisan test
# or
vendor/bin/pest
```

A captured passing run lives in `docs/evidence/test-run.txt`. Screenshot that output for the submission.

## What this repository contains

1. Migrations for subscriptions, allocations, the instructor ledger, and payouts
2. Revenue allocation (platform cut + weighted instructor split + largest remainder)
3. `payouts:dispatch` + `ProcessInstructorPayoutJob`
4. `MockPaymentProvider` (success / permanent fail / timeout-after-success)
5. Pest tests for the money invariants
6. Read-only Filament screen: instructor balance + payout + ledger history

## Assumptions (decided, not left open)

Full reasoning is in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Short version:

| Question | Decision |
|---|---|
| When is money earned? | On successful collection of the prepaid term, not drip-accrued daily |
| How is a payment split? | 70% instructor pool (plan-configurable bps), weighted by **enrolled course count at payment time**, snapshotted |
| Uneven cents | Integer minor units + Hamilton / largest-remainder. Pool + platform cut always equals the payment |
| Mid-term refund | Claw back the instructor pool in proportion to the refunded fraction of the original payment. Already-paid amounts go negative and offset future payouts |
| Uncertain provider | Debit (hold) **before** the HTTP call. Timeout leaves the payout `unknown` and blocks a second payout. Status check / idempotent retry discovers the truth |
| No enrollments | 100% platform. There is no instructor to pay |

## Commands

| Command | Role |
|---|---|
| `payouts:dispatch --min=0 --limit=500` | Lock instructors, post holds, queue transfers |
| `payouts:reconcile` | Resolve `unknown` payouts via provider status |

## Video walkthrough (15–20 min)

Record this against the running app and `vendor/bin/pest`.

1. **Intro (2–3 min)** — who you are, any payments/ledger work, why an append-only ledger with holds beats “update a balance column and hope”
2. **Architecture (5 min)** — schema, allocation, cached `available_balance_cents` vs ledger as source of truth, payout state machine
3. **Failures (5–7 min)** — run the Pest files in `tests/Feature` live; show timeout then `payouts:reconcile`; show a refund after payout leaving a negative balance
4. **Tests (2–3 min)** — what each file protects
5. **AI + decisions (2–3 min)** — see `docs/AI_USAGE.md`
6. **If this were production (1–2 min)** — partitioning, Redis locks alongside row locks, real PSP, daily accrued revenue as a *reporting* layer not the cash ledger

Senior bonus (discussion only, not built): mid-term plan change is outlined at the end of `docs/ARCHITECTURE.md`.
