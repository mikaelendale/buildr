<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * AI jobs pause when the daily budget is spent or an admin paused AI.
 * Jobs catch this and release themselves for later.
 */
class AiBudgetExceededException extends RuntimeException {}
