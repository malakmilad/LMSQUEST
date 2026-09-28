<?php

namespace App\Domain\Ledger;

use App\Domain\Money\Money;

final readonly class InstructorPosition
{
    public function __construct(
        public Money $lifetimeEarned,
        public Money $lifetimeClawedBack,
        public Money $paid,
        public Money $inFlight,
        public Money $available,
        public Money $ledgerBalance,
    ) {}

    /**
     * Net instructor share still not confirmed as paid.
     * Equals available + in-flight holds.
     */
    public function outstanding(): Money
    {
        return $this->available->add($this->inFlight);
    }
}
