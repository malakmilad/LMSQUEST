<?php

namespace App\Services\Money;

use InvalidArgumentException;

/**
 * Integer-only money splitting. Every function returns parts that sum exactly
 * to the input; no minor unit is ever created or lost.
 */
final class Allocator
{
    /**
     * Split $amountMinor proportionally to integer $weights using the largest
     * remainder method. Leftover units go to the largest fractional remainders;
     * ties go to the key that appears first, so callers control tie-breaking by
     * ordering the weights (we always order by instructor id / period number).
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return array<TKey, int>
     */
    public static function allocate(int $amountMinor, array $weights): array
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Cannot allocate a negative amount.');
        }

        if ($weights === []) {
            throw new InvalidArgumentException('Cannot allocate across zero parts.');
        }

        $totalWeight = 0;
        foreach ($weights as $weight) {
            if (! is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException('Weights must be non-negative integers.');
            }
            $totalWeight += $weight;
        }

        if ($totalWeight === 0) {
            throw new InvalidArgumentException('At least one weight must be positive.');
        }

        $shares = [];
        $remainders = [];
        $position = 0;

        foreach ($weights as $key => $weight) {
            $product = $amountMinor * $weight;
            $shares[$key] = intdiv($product, $totalWeight);
            $remainders[] = ['key' => $key, 'remainder' => $product % $totalWeight, 'position' => $position++];
        }

        $leftover = $amountMinor - array_sum($shares);

        usort($remainders, fn (array $a, array $b) => [$b['remainder'], $a['position']] <=> [$a['remainder'], $b['position']]);

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$remainders[$i]['key']]++;
        }

        return $shares;
    }

    /** Floor of $amountMinor * $basisPoints / 10000. */
    public static function percentage(int $amountMinor, int $basisPoints): int
    {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new InvalidArgumentException('Basis points must be between 0 and 10000.');
        }

        return intdiv($amountMinor * $basisPoints, 10_000);
    }

    /**
     * Split into $parts near-equal integers; earlier parts absorb the remainder.
     *
     * @return list<int>
     */
    public static function evenly(int $amountMinor, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least 1.');
        }

        return array_values(self::allocate($amountMinor, array_fill(0, $parts, 1)));
    }

    public static function format(int $amountMinor, string $currency): string
    {
        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = abs($amountMinor);

        return sprintf('%s%s %s.%02d', $sign, $currency, number_format(intdiv($absolute, 100)), $absolute % 100);
    }
}
