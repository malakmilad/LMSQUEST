<?php

namespace App\Domain\Ledger;

use App\Enums\LedgerEntryType;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class LedgerPoster
{
    /**
     * Append-only write. Duplicate idempotency keys return the existing row
     * and never touch the cached instructor balance a second time.
     *
     * @param  array{
     *     instructor_id?: int|null,
     *     type: LedgerEntryType,
     *     amount_cents: int,
     *     currency?: string,
     *     subscription_payment_id?: int|null,
     *     refund_id?: int|null,
     *     payout_id?: int|null,
     *     idempotency_key: string,
     *     description?: string|null,
     *     occurred_at?: \DateTimeInterface|null
     * }  $attributes
     */
    public function post(array $attributes): LedgerEntry
    {
        $type = $attributes['type'];
        $amountCents = (int) $attributes['amount_cents'];
        $instructorId = $attributes['instructor_id'] ?? null;
        $currency = $attributes['currency'] ?? config('revenue.currency');
        $key = $attributes['idempotency_key'];

        if ($amountCents === 0) {
            $existing = LedgerEntry::query()->where('idempotency_key', $key)->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(function () use ($attributes, $type, $amountCents, $instructorId, $currency, $key) {
                $entry = LedgerEntry::query()->create([
                    'instructor_id' => $instructorId,
                    'type' => $type,
                    'amount_cents' => $amountCents,
                    'currency' => $currency,
                    'subscription_payment_id' => $attributes['subscription_payment_id'] ?? null,
                    'refund_id' => $attributes['refund_id'] ?? null,
                    'payout_id' => $attributes['payout_id'] ?? null,
                    'idempotency_key' => $key,
                    'description' => $attributes['description'] ?? null,
                    'occurred_at' => $attributes['occurred_at'] ?? now(),
                    'created_at' => now(),
                ]);

                if ($instructorId !== null && $amountCents !== 0) {
                    Instructor::query()
                        ->whereKey($instructorId)
                        ->increment('available_balance_cents', $amountCents);
                }

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return LedgerEntry::query()
                ->where('idempotency_key', $key)
                ->firstOrFail();
        }
    }
}
