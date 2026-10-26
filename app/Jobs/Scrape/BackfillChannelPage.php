<?php

namespace App\Jobs\Scrape;

use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\Concerns\HandlesScrapeFailures;
use App\Models\Channel;
use App\Services\Scraping\ChannelScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches one older page and re-dispatches itself until the history (or the
 * backfill caps) run out, then hands the channel to the AI pipeline.
 */
class BackfillChannelPage implements ShouldQueue
{
    use HandlesScrapeFailures, Queueable;

    public function __construct(public int $channelId, public bool $processWhenDone = true)
    {
        $this->onQueue('scrape');
    }

    public function handle(ChannelScraper $scraper): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel === null || $channel->isRemoved()) {
            return;
        }

        $this->runScrape($channel, function (Channel $channel) use ($scraper) {
            if ($scraper->backfillPage($channel)) {
                self::dispatch($channel->id, $this->processWhenDone);

                return;
            }

            if ($this->processWhenDone) {
                ProcessChannel::dispatch($channel->id);
            }
        });
    }
}
