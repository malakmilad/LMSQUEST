# AI usage

AI tools were used. The design is mine. This file is so a reviewer can tell the difference.

## How I used AI

I worked in **Cursor** (agent on the Grok 4.6 model) against an empty Laravel tree plus a personal architecture notes file.

Typical loop:

1. I specified the quest constraints (idempotency, timeout-after-success, 500k scale, Pest, Filament read-only)
2. I decided the ledger / hold-before-call / snapshot-weights model (see below)
3. The agent scaffolded Laravel 11, Filament v3, Pest 3, and wrote first-pass PHP
4. I reviewed every money path, tightened tests, and rewrote the docs in my voice

I did **not** paste the quest into a chatbot and submit the first zip file.

## Prompts / workflows I actually relied on

- “Scaffold Laravel 11 + Filament v3 + Pest in this repo; keep `LARAVEL_ARCHITECTURE.md`.”
- “Implement an append-only instructor ledger with unique idempotency keys and a cached balance updated in the same transaction.”
- “MockPaymentProvider must persist success *before* returning timeout, and retries must return the actual status.”
- “Pest coverage: double dispatch, duplicate handle(), timeout, reconcile, refund before and after payout, largest remainder.”
- Follow-up: fix Composer advisory blocking on Laravel 11 / Filament 3, bind the mock provider as a singleton, force `queue.default=sync` in the seeder.

I am not dumping the raw prompt log. The list above is the workflow.

## Generated vs designed

| Piece | Who |
|---|---|
| Laravel/Filament/Pest boilerplate, model fillable, Filament table columns | Mostly generated, then trimmed |
| Integer `Money` VO, largest-remainder split, tie-break by instructor id | Specified by me; implementation generated and checked against hand-calculated examples (70 ÷ 3, 7000 × 2:1) |
| Earn-on-collection vs daily accrual | I chose earn-on-collection. I rejected daily accrual at 500k subscriptions |
| Hold-before-provider-call + `unknown` status | I chose this. A generated “mark paid on success” design was considered and rejected |
| Allocation snapshot at payment time | I chose this. Retroactive re-split on new enrollments was rejected |
| Refund = clawback of `pool * refund/original`, negative balances allowed | I chose this |
| `instructors.in_flight_payout_id` instead of a MySQL partial unique index | I chose this (MySQL cannot do the Postgres-style partial unique) |
| Tests for the failure matrix | I listed the cases; the agent wrote them; I corrected the 50% refund cent split (2334 / 1166) |
| `docs/ARCHITECTURE.md` structure and trade-offs | Mine. Prose was drafted with AI and then rewritten so I can defend it live |
| Senior bonus (plan change) | Mine: new payment + new snapshot, never mutate the old term |

## Engineering decisions I made myself

1. **Ledger is the source of truth.** The balance column is a projection. If they disagree, the ledger wins and tests fail.
2. **Debit the instructor before calling the PSP.** Conservative under uncertainty. The expensive mistake is double-pay, not a delayed pay.
3. **Timeout is not failure.** Failure reverses money. Timeout leaves it in limbo on purpose.
4. **Provider idempotency key = payout ULID.** Worker crash after HTTP success is a retry of the same transfer, not a new one.
5. **Do not randomize in tests.** `MockPaymentProvider` throws in `testing` unless you `script()`. Demos can be random; correctness tests cannot.
6. **No repository layer on the ledger.** The locking *is* the business logic. Hiding `lockForUpdate()` behind `InstructorRepository::find()` would be theatre.
7. **Filament is read-only.** The quest asked for a view, not an admin who can edit cents.

## What I told the model to stop doing

- Daily accrual jobs
- `float` / `decimal(8,2)` as the unit of record
- Equal split that ignores course weights
- Paying on provider success without a hold
- Catching `\Exception` around the PSP and retrying with a new payout id
- Building a full LMS (courses player, auth product, etc.)
- Copying the entire `LARAVEL_ARCHITECTURE.md` modular monolith into this repo

## What differentiates this from a typical AI-generated submission

Most generated solutions will have: a `balance` column, a command that `SUM`s and `UPDATE`s it, a mock that `rand()`s inside the test suite, and a README that says “idempotent” without a unique key.

This one has:

- An immutable ledger with unique keys as the actual mutex
- A provider that can succeed in its own database while returning timeout to us
- A hold that makes “I am not sure” a first-class state
- Tests that fail if you run the command twice, handle the job twice, or refund after payout without going negative
- Written trade-offs for the questions the quest left blank

If you ask me in the review to change the weight function to watch-time, or to add a 14-day reserve, that is a new entry type plus a payable rule — not a rewrite.

## Trade-offs I accepted

- Immediate recognition vs deferred revenue (see Architecture)
- Course-count weights vs engagement (no data in the brief)
- Negative balances vs attempting PSP clawbacks
- SQLite in CI vs InnoDB gap locks (unique keys still hold)
- Laravel 11 / Filament 3 as specified, even though Composer currently flags those lines as having advisories. The quest pinned the versions; I did not silently upgrade to Laravel 12 / Filament 4
