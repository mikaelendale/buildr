<?php

namespace App\Console\Commands;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelAvailability;
use App\Models\Channel;
use App\Services\Scraping\ChannelScraper;
use App\Support\TelegramUsername;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('buildr:scrape {username : Channel @handle or t.me link} {--pages=0 : Stop the backfill after N pages (0 = no limit)} {--process : Run the AI pipeline afterwards}')]
#[Description('Fetch channel info and backfill posts synchronously (manual testing, plan Phase 1)')]
class ScrapeChannel extends Command
{
    public function handle(ChannelScraper $scraper): int
    {
        $username = TelegramUsername::normalize($this->argument('username'));

        if ($username === null) {
            $this->error('That does not look like a Telegram username.');

            return self::FAILURE;
        }

        $channel = Channel::firstOrCreate(['username' => $username], ['added_by' => ChannelAddedBy::Admin]);

        try {
            $info = $scraper->refreshInfo($channel);
        } catch (Throwable $e) {
            $this->error('Fetching channel info failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $channel->refresh();
        $this->info(sprintf('%s (@%s): %s, %s subscribers, availability: %s',
            $info->title ?? '—', $username, $info->pageType->value, $info->subscribers ?? '?', $channel->availability->value));

        if ($channel->availability !== ChannelAvailability::Ok) {
            $this->warn('Not a public channel with a web preview; nothing to backfill.');

            return self::SUCCESS;
        }

        $maxPages = (int) $this->option('pages');
        $page = 0;

        do {
            $more = $scraper->backfillPage($channel);
            $channel->refresh();
            $page++;
            $this->line("  page {$page}: {$channel->posts()->count()} posts, oldest msg_id {$channel->oldest_msg_id}");
        } while ($more && ($maxPages === 0 || $page < $maxPages));

        $this->info("Done: {$channel->posts()->count()} posts stored.");

        if ($this->option('process')) {
            $this->call('buildr:process', ['username' => $username, '--sync' => true]);
        }

        return self::SUCCESS;
    }
}
