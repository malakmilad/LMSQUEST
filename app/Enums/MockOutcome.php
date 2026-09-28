<?php

namespace App\Enums;

enum MockOutcome: string
{
    case Success = 'success';

    /** Provider rejects the transfer; no money moves. */
    case PermanentFailure = 'permanent_failure';

    /** Provider moves the money, then the response is lost. */
    case TimeoutAfterSuccess = 'timeout_after_success';

    /** Request never reaches the provider. */
    case TimeoutBeforeReceipt = 'timeout_before_receipt';

    /** Provider accepts the transfer and settles it later. */
    case Accepted = 'accepted';
}
