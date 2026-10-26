<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The provider's longer rate limit (requests or tokens per day) is spent for
 * the model and its fallback. Jobs reschedule themselves for when the
 * provider says the quota is back, instead of failing.
 */
class AiQuotaExhaustedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
