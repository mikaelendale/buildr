<?php

namespace App\Jobs\Scrape;

use App\Jobs\Scrape\Concerns\HandlesScrapeFailures;
use App\Models\Channel;
use App\Services\Scraping\ChannelScraper;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IncrementalScrape implements ShouldBeUnique, ShouldQueue
{
    use HandlesScrapeFailures, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public int $channelId)
    {
        $this->onQueue('scrape');
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    public function handle(ChannelScraper $scraper): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel === null || $channel->isRemoved() || ! $channel->backfill_complete) {
            return;
        }

        $this->runScrape($channel, function (Channel $channel) use ($scraper) {
            $scraper->incremental($channel);
        });
    }
}
