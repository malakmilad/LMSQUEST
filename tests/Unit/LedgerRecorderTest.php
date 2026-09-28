<?php

use App\Enums\LedgerEntryType;
use App\Services\Ledger\LedgerRecorder;

// Lives in the Unit suite: Feature tests run inside RefreshDatabase's wrapping transaction.
it('refuses ledger writes outside a database transaction', function () {
    app(LedgerRecorder::class)->record(1, LedgerEntryType::Adjustment, 1, 'EGP', 'adjustment:x');
})->throws(LogicException::class, 'inside a database transaction');
