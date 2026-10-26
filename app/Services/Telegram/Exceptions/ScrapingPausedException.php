<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * The global kill switch is on (admin or canary paused scraping).
 */
class ScrapingPausedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Scraping is paused.');
    }
}
