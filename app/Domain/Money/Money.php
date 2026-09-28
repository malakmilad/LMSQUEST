<?php

namespace App\Domain\Money;

use InvalidArgumentException;

/**
 * Integer minor-unit money. Never constructed from a float.
 */
final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'EGP',
    ) {
        if ($this->currency === '') {
            throw new InvalidArgumentException('Currency cannot be empty.');
        }
    }

    public static function of(int $cents, string $currency = 'EGP'): self
    {
        return new self($cents, $currency);
    }

    public static function zero(string $currency = 'EGP'): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    /**
     * Floor of (amount * bps / 10_000). 7000 bps = 70%.
     */
    public function shareBps(int $bps): self
    {
        if ($bps < 0 || $bps > 10_000) {
            throw new InvalidArgumentException('Basis points must be between 0 and 10000.');
        }

        return new self(intdiv($this->cents * $bps, 10_000), $this->currency);
    }

    /**
     * Hamilton / largest-remainder split. Sum of parts === this amount.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, self>
     */
    public function splitByWeights(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Weights cannot be empty.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('Weights cannot be negative.');
            }
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight === 0) {
            throw new InvalidArgumentException('Weights must sum to more than zero.');
        }

        $floors = [];
        $remainders = [];
        $distributed = 0;

        foreach ($weights as $key => $weight) {
            $numerator = $this->cents * $weight;
            $floor = intdiv($numerator, $totalWeight);
            $floors[$key] = $floor;
            $remainders[$key] = $numerator % $totalWeight;
            $distributed += $floor;
        }

        $leftover = $this->cents - $distributed;

        $keys = array_keys($remainders);
        usort($keys, function ($a, $b) use ($remainders): int {
            $cmp = $remainders[$b] <=> $remainders[$a];

            return $cmp !== 0 ? $cmp : $a <=> $b;
        });

        foreach ($keys as $key) {
            if ($leftover <= 0) {
                break;
            }

            $floors[$key]++;
            $leftover--;
        }

        $result = [];

        foreach ($floors as $key => $cents) {
            $result[$key] = new self($cents, $this->currency);
        }

        return $result;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents >= $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function format(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absolute = abs($this->cents);
        $major = intdiv($absolute, 100);
        $minor = $absolute % 100;

        return sprintf('%s%d.%02d %s', $sign, $major, $minor, $this->currency);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
