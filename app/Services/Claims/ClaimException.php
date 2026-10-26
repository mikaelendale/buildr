<?php

namespace App\Services\Claims;

use RuntimeException;

/**
 * A pre-check failed; the message is shown to the user as-is.
 */
class ClaimException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
