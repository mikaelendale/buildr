<?php

namespace App\Jobs\Scrape;

use App\Models\Setting;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\Exceptions\ScrapingPausedException;
use App\Services\Telegram\Exceptions\TelegramFetchException;
use App\Services\Telegram\TelegramWebClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hourly (plan §7.8): scrape a known, stable public channel. Zero posts or
 * missing fields means Telegram changed its markup, so scraping is paused
 * before bad data gets written.
 */
class RunScraperCanary implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('scrape');
    }

    public function handle(TelegramWebClient $client, TelegramNotifier $notifier): void
    {
        $username = (string) config('buildr.scrape.canary_channel');

        try {
            $info = $client->channelInfo($username);
            $page = $client->feed($username);
        } catch (ScrapingPausedException) {
            return;
        } catch (TelegramFetchException $e) {
            $this->record(false, 'Fetch failed: '.$e->getMessage(), pause: false);

            return;
        }

        $problems = [];

        if (! $info->isChannel() || $info->title === null) {
            $problems[] = 'channel info did not parse';
        }

        if (count($page->posts) === 0) {
            $problems[] = 'feed parsed 0 posts';
        }

        $withDates = collect($page->posts)->filter(fn ($p) => $p->postedAt !== null)->count();
        $withViews = collect($page->posts)->filter(fn ($p) => $p->views !== null)->count();

        if ($page->posts !== [] && ($withDates < count($page->posts) * 0.8 || $withViews < count($page->posts) * 0.5)) {
            $problems[] = "fields missing (dates {$withDates}, views {$withViews} of ".count($page->posts).')';
        }

        if ($problems === []) {
            $this->record(true, count($page->posts).' posts parsed');

            return;
        }

        $message = implode('; ', $problems);
        $this->record(false, $message, pause: true);

        $notifier->toAdmin("Scraper canary failed on @{$username}: {$message}. <b>Scraping is paused.</b>");
    }

    private function record(bool $ok, string $message, bool $pause = false): void
    {
        Setting::write(Setting::CANARY_STATUS, [
            'ok' => $ok,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
        ]);

        if ($pause) {
            Setting::write(Setting::SCRAPING_PAUSED, true);
        }
    }
}
