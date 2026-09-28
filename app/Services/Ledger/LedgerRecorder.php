<?php

namespace App\Services\Ledger;

use App\Enums\LedgerEntryType;
use App\Exceptions\LedgerException;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single write path into ledger_entries.
 *
 * Every entry is keyed by a business `entry_key` (UNIQUE in the database), so
 * recording the same event twice returns the original entry and leaves the
 * balance untouched. The entry and its projection update share one transaction
 * and one row lock on instructor_balances, so they can never drift apart.
 */
final class LedgerRecorder
{
    /**
     * @param  array{subscription_id?: int|null, subscription_payment_id?: int|null, revenue_allocation_id?: int|null, refund_id?: int|null, payout_id?: int|null}  $references
     */
    public function record(
        int $instructorId,
        LedgerEntryType $type,
        int $amountMinor,
        string $currency,
        string $entryKey,
        array $references = [],
        array $metadata = [],
        ?CarbonImmutable $occurredAt = null,
    ): LedgerEntry {
        $this->assertInTransaction();

        if (! $type->acceptsAmount($amountMinor)) {
            throw LedgerException::invalidSign($type->value, $amountMinor);
        }

        $balance = $this->lockBalance($instructorId);

        $existing = LedgerEntry::query()->where('entry_key', $entryKey)->first();

        if ($existing !== null) {
            if ($existing->instructor_id !== $instructorId || $existing->type !== $type || $existing->amount_minor !== $amountMinor) {
                throw LedgerException::conflictingReplay($entryKey);
            }

            return $existing;
        }

        if ($balance->currency !== $currency) {
            throw LedgerException::currencyMismatch($balance->currency, $currency);
        }

        $entry = LedgerEntry::query()->create([
            'instructor_id' => $instructorId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'entry_key' => $entryKey,
            'subscription_id' => $references['subscription_id'] ?? null,
            'subscription_payment_id' => $references['subscription_payment_id'] ?? null,
            'revenue_allocation_id' => $references['revenue_allocation_id'] ?? null,
            'refund_id' => $references['refund_id'] ?? null,
            'payout_id' => $references['payout_id'] ?? null,
            'metadata' => $metadata ?: null,
            'occurred_at' => $occurredAt ?? CarbonImmutable::now(),
        ]);

        $this->applyToProjection($balance, $type, $amountMinor);

        return $entry;
    }

    /**
     * Lock (creating if needed) the instructor's balance row. This row is the
     * per-instructor mutex for every money movement. Must be called inside a transaction.
     */
    public function lockBalance(int $instructorId): InstructorBalance
    {
        $this->assertInTransaction();

        $balance = InstructorBalance::query()->where('instructor_id', $instructorId)->lockForUpdate()->first();

        if ($balance !== null) {
            return $balance;
        }

        InstructorBalance::query()->insertOrIgnore([
            'instructor_id' => $instructorId,
            'currency' => Instructor::query()->whereKey($instructorId)->value('currency') ?? config('revenue.currency'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InstructorBalance::query()->where('instructor_id', $instructorId)->lockForUpdate()->firstOrFail();
    }

    private function applyToProjection(InstructorBalance $balance, LedgerEntryType $type, int $amountMinor): void
    {
        match ($type) {
            LedgerEntryType::Earning => $balance->earned_minor += $amountMinor,
            LedgerEntryType::RefundReversal => $balance->reversed_minor += -$amountMinor,
            LedgerEntryType::Adjustment => $balance->adjusted_minor += $amountMinor,
            LedgerEntryType::Payout => $balance->paid_minor += -$amountMinor,
        };

        $position = $balance->position();
        $balance->outstanding_minor = max($position, 0);
        $balance->recoverable_minor = max(-$position, 0);
        $balance->version++;
        $balance->save();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger writes must happen inside a database transaction.');
        }
    }
}
