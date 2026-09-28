<?php

namespace App\Services\Payments;

use RuntimeException;

/** No usable response. The transfer may or may not have happened. */
class ProviderTimeoutException extends RuntimeException {}
