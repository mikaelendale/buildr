<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * A fetch failed in a way that is worth retrying later (429, 5xx, timeouts).
 */
class TelegramFetchException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
