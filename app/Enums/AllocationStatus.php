<?php

namespace App\Enums;

enum AllocationStatus: string
{
    /** Still has periods to recognize. */
    case Active = 'active';

    /** Every period recognized. */
    case Completed = 'completed';

    /** Refunded; remaining periods will never be recognized. */
    case Cancelled = 'cancelled';
}
