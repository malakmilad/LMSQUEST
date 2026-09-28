<?php

namespace App\Enums;

enum ProviderReportedStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Timeout = 'timeout';
}
