<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

class BlockedUsernameException extends RuntimeException
{
    public function __construct(string $username)
    {
        parent::__construct("@{$username} is on the scrape blocklist.");
    }
}
