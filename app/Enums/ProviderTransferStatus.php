<?php

namespace App\Enums;

enum ProviderTransferStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Accepted, not settled yet. */
    case Pending = 'pending';

    /** Provider has no transfer with this idempotency key: it never arrived. */
    case NotFound = 'not_found';

    /** Provider could not answer (status endpoint down, ambiguous reply). */
    case Unknown = 'unknown';
}
