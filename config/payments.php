<?php

return [

    'mock' => [
        /*
        | How the mock provider decides an outcome when nothing is scripted,
        | forced, or implied by the destination:
        |   success | permanent_failure | timeout_after_success | random
        | Tests run with "success" and script every other outcome explicitly.
        */
        'mode' => env('MOCK_PROVIDER_MODE', 'success'),

        'random_weights' => [
            'success' => 60,
            'permanent_failure' => 20,
            'timeout_after_success' => 20,
        ],

        /*
        | Destination prefixes that force an outcome, like test card numbers.
        | Used by the seeder and for live demos.
        */
        'magic_destinations' => [
            'acct_fail_' => 'permanent_failure',
            'acct_timeout_' => 'timeout_after_success',
            'acct_lost_' => 'timeout_before_receipt',
            'acct_accept_' => 'accepted',
        ],
    ],

];
