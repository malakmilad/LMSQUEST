<?php

namespace App\Enums;

enum RefundType: string
{
    /** Whole payment returned; every recognized earning is reversed. */
    case Full = 'full';

    /** Only periods that have not started are returned; earned periods stay earned. */
    case Prorated = 'prorated';
}
