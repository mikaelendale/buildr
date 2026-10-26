<?php

namespace App\Jobs\Scrape\Concerns;

use App\Enums\ScrapeStatus;
use App\Models\Channel;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\Exceptions\BlockedUsernameException;
use App\Services\Telegram\Exceptions\ScrapingPausedException;
use App\Services\Telegram\Exceptions\TelegramFetchException;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Shared retry policy for scrape jobs (plan §7.3): 429/5xx back off 1m, 5m,
 * 30m, 2h; after 5 consecutive failures the channel is marked failed and the
 * admin is alerted. The kill switch just parks the job for later.
 */
trait HandlesScrapeFailures
{
    public int $tries = 10;

    /**
     * @param  Closure(Channel): void  $work
     */
    protected function runScrape(Channel $channel, Closure $work): void
    {
        try {
            $work($channel);
        } catch (ScrapingPausedException) {
            $this->release(15 * 60);
        } catch (BlockedUsernameException $e) {
            Log::channel('buildr')->info($e->getMessage());
            $channel->forceFill(['scrape_status' => ScrapeStatus::Idle])->save();
        } catch (TelegramFetchException $e) {
            $this->recordFailure($channel, $e);
        }
    }

    protected function recordFailure(Channel $channel, TelegramFetchException $e): void
    {
        $failures = $channel->failure_count + 1;
        $max = (int) config('buildr.scrape.max_consecutive_failures');
        $backoff = (array) config('buildr.scrape.backoff_minutes');

        $channel->forceFill([
            'failure_count' => $failures,
            'last_error' => $e->getMessage(),
        ])->save();

        Log::channel('buildr')->warning("Scrape failed for @{$channel->username}", [
            'failures' => $failures,
            'error' => $e->getMessage(),
        ]);

        if ($failures >= $max) {
            $channel->forceFill(['scrape_status' => ScrapeStatus::Failed])->save();

            app(TelegramNotifier::class)->toAdmin(
                "Scraping <b>@{$channel->username}</b> failed {$failures} times in a row: ".e($e->getMessage()),
            );

            return;
        }

        $minutes = (int) ($backoff[min($failures - 1, count($backoff) - 1)] ?? 5);
        $this->release($minutes * 60);
    }
}
