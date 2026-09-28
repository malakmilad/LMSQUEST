<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ledger currency
    |--------------------------------------------------------------------------
    |
    | All money in this bounded context is stored as integer minor units
    | (piastres). One EGP = 100 cents in this codebase.
    |
    */
    'currency' => env('REVENUE_CURRENCY', 'EGP'),

    /*
    | Default instructor share of a subscription payment, in basis points.
    | 7000 = 70% to instructors, 30% platform. Overridable per plan.
    */
    'default_instructor_share_bps' => (int) env('REVENUE_INSTRUCTOR_SHARE_BPS', 7000),

    /*
    | Instructors below this available balance are skipped by payouts:dispatch.
    | Tests pass --min=0 to exercise the exact ledger numbers.
    */
    'min_payout_cents' => (int) env('REVENUE_MIN_PAYOUT_CENTS', 10_000),

    'mock_provider' => [
        'success_weight' => 50,
        'permanent_fail_weight' => 25,
        'timeout_after_success_weight' => 25,
    ],

];
