<?php

namespace App\Jobs\Scrape;

use App\Enums\ChannelAvailability;
use App\Enums\ScrapeStatus;
use App\Jobs\Scrape\Concerns\HandlesScrapeFailures;
use App\Models\Channel;
use App\Services\Scraping\ChannelScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * t.me/{u} → title, description, avatar, subscribers, page type + a daily
 * subscriber snapshot. Optionally kicks off the backfill afterwards.
 */
class FetchChannelInfo implements ShouldQueue
{
    use HandlesScrapeFailures, Queueable;

    public function __construct(public int $channelId, public bool $thenBackfill = false)
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
            $scraper->refreshInfo($channel);
            $channel->refresh();

            if (! $this->thenBackfill) {
                return;
            }

            if ($channel->availability === ChannelAvailability::Ok) {
                $channel->forceFill(['scrape_status' => ScrapeStatus::Queued])->save();
                BackfillChannelPage::dispatch($channel->id);
            } else {
                $channel->forceFill(['scrape_status' => ScrapeStatus::Idle])->save();
            }
        });
    }
}
