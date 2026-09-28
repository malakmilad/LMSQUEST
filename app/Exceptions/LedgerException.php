<?php

namespace App\Exceptions;

use RuntimeException;

class LedgerException extends RuntimeException
{
    public static function immutable(): self
    {
        return new self('Ledger entries are append-only. Post a reversing or adjustment entry instead.');
    }

    public static function invalidSign(string $type, int $amountMinor): self
    {
        return new self("A {$type} ledger entry cannot have amount {$amountMinor}.");
    }

    public static function conflictingReplay(string $entryKey): self
    {
        return new self("Ledger entry [{$entryKey}] already exists with a different type or amount.");
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self("Ledger currency mismatch: balance is {$expected}, entry is {$actual}.");
    }
}
