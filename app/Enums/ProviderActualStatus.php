<?php

namespace App\Enums;

enum ProviderActualStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
