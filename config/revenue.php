<?php

return [

    /*
    | All money in this bounded context is stored as integer minor units
    | (1 EGP = 100 minor units). No floats anywhere in the money path.
    */
    'currency' => env('REVENUE_CURRENCY', 'EGP'),

    /*
    | Platform share of every subscription payment, in whole percent.
    | The remainder is the instructor pool. The platform share is floored,
    | so any sub-minor-unit rounding benefit goes to instructors.
    | The value is snapshotted onto each payment at allocation time.
    */
    'platform_percentage' => (int) env('REVENUE_PLATFORM_PERCENTAGE', 20),

    /*
    | Prepaid plans. A subscription term is recognized one month-period at a
    | time: period n is earned when it starts (see docs/ARCHITECTURE.md).
    */
    'plans' => [
        'monthly' => ['months' => 1, 'price_minor' => 30_000],
        'three_month' => ['months' => 3, 'price_minor' => 80_000],
        'annual' => ['months' => 12, 'price_minor' => 300_000],
    ],

    'payouts' => [
        // Instructors whose outstanding balance is below this are not paid yet.
        'min_amount_minor' => (int) env('PAYOUT_MIN_AMOUNT_MINOR', 10_000),

        // A claimed payout belongs to one worker for this long. Must exceed the
        // provider HTTP timeout, otherwise a slow-but-alive worker gets taken over.
        'claim_lease_seconds' => (int) env('PAYOUT_CLAIM_LEASE_SECONDS', 300),

        // Pending payouts older than this get their job re-dispatched by payouts:process.
        'redispatch_after_seconds' => (int) env('PAYOUT_REDISPATCH_AFTER_SECONDS', 600),

        // Delay before a payout in UNKNOWN / SUBMITTED is checked against the provider.
        'reconcile_delay_seconds' => (int) env('PAYOUT_RECONCILE_DELAY_SECONDS', 60),

        'chunk_size' => 500,
    ],

];
