<?php

namespace App\Services\Sponsorships;

use RuntimeException;

/**
 * A sponsorship pre-check or transition failed; the message is shown to the
 * user as-is.
 */
class SponsorshipException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
