# AI usage

AI was used heavily. This file separates what the tools produced from what was decided by a person, so a reviewer can tell the difference.

## How AI was used

The code was written in **Cursor** with its agent, in two passes:

1. **First pass.** From the quest brief, the agent scaffolded Laravel 11 / Filament 3 / Pest 3 and built a first design. That design earned revenue immediately on payment and debited the instructor ("hold") before calling the provider.
2. **Second pass.** I wrote a detailed implementation specification: money rules, recognition policy, entity list, payout state machine, provider contract, required tests, documentation outline and agent operating rules. I had the agent rebuild the money core against it. The specification changed several first-pass decisions (see "Rejected suggestions").

In both passes the agent generated most of the PHP, the tests and first drafts of these documents. It ran the suite on SQLite and on MySQL after each phase.

## What was generated

- Migrations, models, factories and the seeder
- `Allocator` (largest remainder), `LedgerRecorder`, `LedgerVerifier`, `PayoutTransitions`
- The revenue actions (record, allocate, recognize, refund) and payout actions (create, process, submit, reconcile, cancel)
- `MockPaymentProvider` with scripted, forced and destination-based outcomes
- Jobs, Artisan commands, schedule, JSON payout log channel
- Filament resource and relation managers
- All Pest tests, including the MySQL multi-process concurrency test
- Drafts of README and ARCHITECTURE

## Decisions made by a person (in the specification or in review)

- Integer minor units only; configurable platform percentage (default 20%); percentage-based instructor splits that must total 100%.
- Largest-remainder rounding with a deterministic tie-break (100 gives 34/33/33).
- **Progressive recognition** (annual 12,000 gives 1,000 per month) instead of earning everything on payment.
- Ledger as the immutable source of truth with the four entry types and sign convention; `instructor_balances` as a transactional projection with a recoverable column.
- Payout ledger entry **only on confirmed success**; permanent failure has no ledger effect.
- Timeout means UNKNOWN, never FAILED; reconcile through `status(key)`; one idempotency key reused on every retry.
- No transaction held across the provider call; `lockForUpdate` claims.
- Refund after payout produces a recoverable balance offset against future payouts.
- The list of required failure-scenario tests and business-style test names.
- Filament stays read-only.

## Choices the agent made that I reviewed and kept

- `open_instructor_id` nullable UNIQUE column as MySQL's substitute for a partial unique index ("one open payout per instructor").
- Claim lease (`claimed_until`) plus status-first recovery for crashed workers, rather than trusting job middleware.
- Fallback split when a subscription has no percentages: equal share per course. Mixed or partial percentages are rejected rather than guessed.
- A subscription with no courses: the platform keeps 100%.
- Prorated refund amount computed from the same per-period splits as recognition, so earned + kept + refunded equals the payment exactly.
- Re-checking `outstanding` at claim time so a refund that lands between payout creation and sending cancels the payout.
- The mock's `submission_count` and `beforeTransfer` hook, so tests can assert "no second transfer" and interleave two workers deterministically.
- MySQL CHECK constraints as a second line of defence behind the application checks.

## Rejected suggestions

| Suggestion | Why it was rejected |
|---|---|
| Debit (hold) the instructor **before** calling the provider (the first-pass design) | Replaced by "ledger entry only on confirmed success". Double-pay protection now comes from the open-payout guard, the fixed idempotency key and status-first reconciliation. A failed transfer never touches the books. |
| Recognize the full payment immediately | Violates the recognition policy; makes mid-term refunds claw back money for months already served. |
| Mark a payout `failed` when the provider call throws | Timeout after success would then pay twice on retry. Any exception now means `unknown`. |
| Retry an uncertain payout with a new idempotency key / new payout | Same double-pay risk. The key is fixed at creation and reused. |
| Wrap the provider call in the DB transaction | Holds row locks for an HTTP round trip. It also rolls back our record while the money has already moved. |
| Rely on `WithoutOverlapping` / `ShouldBeUnique` for exactly-once | Cache locks expire and queues redeliver. Kept only as a courtesy; the row claim is the real guard. |
| `Instructor::all()` in the payout batch | Unbounded memory at 500k scale; replaced by `chunkById` on indexed predicates. |
| Random mock outcomes in tests | Non-deterministic tests. Random mode throws under `runningUnitTests()`. |
| `decimal(10,2)` money columns | Invites float casts in PHP; integer minor units instead. |
| Logging the payout destination for debugging | Account references are sensitive; logs carry ids, keys and references only (tested). |
| Deleting or editing ledger rows to "fix" a refund | History must stay intact; refunds add `refund_reversal` entries. The model throws on update and delete. |

## Verification

The agent's output was not taken on trust. Checks run:

- `ledger:verify` after every money-moving test (projection, allocation, recognition and payout invariants rebuilt from scratch);
- the full suite on MySQL 8.4 as well as SQLite, including real multi-process races;
- the seeded demo (`migrate:fresh --seed`, `payouts:process`, `queue:work`, `payouts:reconcile`) checked by hand against `ledger:show`.
