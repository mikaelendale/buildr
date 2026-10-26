<?php

namespace App\Console\Commands;

use App\Enums\ChannelVisibility;
use App\Enums\CreatorStatus;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Jobs\Scrape\IncrementalScrape;
use App\Jobs\Scrape\RefreshRecentViews;
use App\Models\Channel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily incremental scrapes + subscriber snapshots, weekly view refresh
 * (plan §7.5). Jobs are staggered so they don't all hit t.me at once; the
 * global rate limiter keeps them polite anyway.
 */
#[Signature('buildr:dispatch-scrapes {--weekly : Refresh recent views instead of incremental scrapes}')]
#[Description('Queue scheduled scrapes for every published or pending channel')]
class DispatchScrapes extends Command
{
    public function handle(): int
    {
        $count = 0;

        $this->channels()->orderBy('id')->each(function (Channel $channel) use (&$count) {
            $delay = now()->addSeconds($count * 30);

            if ($this->option('weekly')) {
                RefreshRecentViews::dispatch($channel->id)->delay($delay);
            } else {
                FetchChannelInfo::dispatch($channel->id)->delay($delay);
                IncrementalScrape::dispatch($channel->id)->delay($delay->addSeconds(10));
            }

            $count++;
        });

        $this->info("Queued {$count} channels.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Channel>
     */
    private function channels(): Builder
    {
        return Channel::query()
            ->where('visibility', '!=', ChannelVisibility::Removed)
            ->where('backfill_complete', true)
            ->where(fn ($q) => $q
                ->where('visibility', ChannelVisibility::Published)
                ->orWhere('review_status', 'pending')
                ->orWhereHas('creator', fn ($q) => $q->whereIn('status', [CreatorStatus::Published, CreatorStatus::PendingReview, CreatorStatus::Draft, CreatorStatus::ChangesRequested])));
    }
}
