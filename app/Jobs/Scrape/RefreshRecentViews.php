<?php

namespace App\Jobs\Scrape;

use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\Concerns\HandlesScrapeFailures;
use App\Models\Channel;
use App\Services\Ai\RegenerationPolicy;
use App\Services\Scraping\ChannelScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Weekly: refresh the last 30 days of views, flag deleted posts, and
 * regenerate the summary when enough new material has arrived.
 */
class RefreshRecentViews implements ShouldQueue
{
    use HandlesScrapeFailures, Queueable;

    public function __construct(public int $channelId)
    {
        $this->onQueue('scrape');
    }

    public function handle(ChannelScraper $scraper, RegenerationPolicy $policy): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel === null || $channel->isRemoved() || ! $channel->backfill_complete) {
            return;
        }

        $this->runScrape($channel, function (Channel $channel) use ($scraper, $policy) {
            $scraper->refreshRecentViews($channel);

            if ($policy->shouldRegenerate($channel)) {
                ProcessChannel::dispatch($channel->id);
            }
        });
    }
}
