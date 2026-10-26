<?php

namespace App\Services\Scraping;

use App\Enums\ChannelAvailability;
use App\Enums\ScrapeStatus;
use App\Enums\TelegramPageType;
use App\Jobs\Media\StoreChannelAvatar;
use App\Models\Channel;
use App\Models\ChannelSnapshot;
use App\Services\Telegram\Data\ChannelInfo;
use App\Services\Telegram\Data\FeedPage;
use App\Services\Telegram\Data\ParsedPost;
use App\Services\Telegram\TelegramWebClient;
use Carbon\CarbonImmutable;

/**
 * Orchestrates the scrape steps from plan §7.4 for one channel. Jobs call
 * these methods and own retries/backoff; this class owns the data rules.
 */
class ChannelScraper
{
    private const MAX_INCREMENTAL_PAGES = 15;

    private const MAX_VIEW_REFRESH_PAGES = 10;

    public function __construct(
        private readonly TelegramWebClient $client,
        private readonly PostUpserter $upserter,
    ) {}

    public function refreshInfo(Channel $channel): ChannelInfo
    {
        $info = $this->client->channelInfo($channel->username);

        $availability = match (true) {
            $info->pageType === TelegramPageType::NotFound => ChannelAvailability::NotFound,
            ! $info->isChannel() => ChannelAvailability::Private,
            ! $info->hasPreview => ChannelAvailability::NoPreview,
            default => ChannelAvailability::Ok,
        };

        $attributes = [
            'page_type' => $info->pageType,
            'availability' => $availability,
            'unavailable_since' => $availability === ChannelAvailability::Ok
                ? null
                : ($channel->unavailable_since ?? now()),
        ];

        if ($info->isChannel()) {
            $attributes += [
                'title' => $info->title,
                'description' => $info->description,
                'subscribers' => $info->subscribers ?? $channel->subscribers,
            ];
        }

        $channel->forceFill($attributes)->save();

        if ($info->isChannel() && $info->subscribers !== null) {
            $this->snapshot($channel, $info->subscribers);
        }

        if ($info->avatarUrl !== null) {
            StoreChannelAvatar::dispatch($channel->id, $info->avatarUrl);
        }

        return $info;
    }

    /**
     * Fetch a run of older pages (up to `scrape.pages_per_job`) in parallel
     * bursts of `scrape.concurrency`. Returns true while there is more
     * history to fetch.
     *
     * Bursts use stepped cursors (before=C, C-20, C-40...). Every `before=X`
     * page holds the 20 newest posts below X, so stepped windows only ever
     * overlap (when ids have gaps), never skip posts.
     */
    public function backfillPage(Channel $channel): bool
    {
        $channel->forceFill(['scrape_status' => ScrapeStatus::Backfilling])->save();

        $budget = max(1, (int) config('buildr.scrape.pages_per_job'));
        $concurrency = max(1, (int) config('buildr.scrape.concurrency'));
        $fetched = 0;

        while ($fetched < $budget) {
            $remaining = (int) config('buildr.scrape.backfill_max_posts') - $channel->posts()->count();

            if ($remaining <= 0) {
                return $this->finishBackfill($channel);
            }

            $cursor = $channel->oldest_msg_id;
            $width = $cursor === null ? 1 : min($concurrency, (int) ceil($remaining / 20), $budget - $fetched);
            $befores = $cursor === null
                ? [null]
                : array_values(array_filter(array_map(fn (int $i) => $cursor - 20 * $i, range(0, $width - 1)), fn (int $b) => $b > 0));

            if ($befores === []) {
                return $this->finishBackfill($channel);
            }

            $pages = $this->client->feedMany($channel->username, $befores);
            $fetched += count($pages);

            [$posts, $more, $noPreview] = $this->collectBurst($channel, $befores, $pages);

            if ($noPreview) {
                $channel->forceFill([
                    'availability' => ChannelAvailability::NoPreview,
                    'unavailable_since' => $channel->unavailable_since ?? now(),
                ])->save();

                return $this->finishBackfill($channel);
            }

            // Never more than the newest `backfill_max_posts`, even mid-burst.
            $posts = array_values(collect($posts)->unique(fn ($p) => $p->msgId)->sortByDesc(fn ($p) => $p->msgId)->take($remaining)->all());

            $this->upserter->upsert($channel, $posts);
            $channel->refresh();

            if (! $more || $posts === [] || $this->backfillLimitReached($channel)) {
                return $this->finishBackfill($channel);
            }

            $channel->forceFill(['last_scraped_at' => now(), 'failure_count' => 0])->save();
        }

        return true;
    }

    /**
     * Walk a burst newest-first; stop at the first page that shows the start
     * of history (empty, no older cursor, or past the age cap).
     *
     * @param  list<int|null>  $befores
     * @param  array<int|string, FeedPage>  $pages
     * @return array{0: list<ParsedPost>, 1: bool, 2: bool}
     */
    private function collectBurst(Channel $channel, array $befores, array $pages): array
    {
        $posts = [];

        foreach ($befores as $i => $before) {
            $page = $pages[(string) $before] ?? null;

            if ($page === null) {
                return [$posts, false, false];
            }

            if (! $page->hasPreview) {
                return [$posts, false, true];
            }

            if ($i === 0) {
                $this->applyFeedHeader($channel, $page);
            }

            $valid = $before === null
                ? $page->posts
                : array_values(array_filter($page->posts, fn ($p) => $p->msgId < $before));

            if ($valid === []) {
                return [$posts, false, false];
            }

            array_push($posts, ...$valid);

            $oldest = collect($valid)->min(fn ($p) => $p->postedAt);
            $tooOld = $oldest instanceof CarbonImmutable
                && $oldest->lt(now()->subMonths((int) config('buildr.scrape.backfill_max_months')));

            if ($page->olderCursor === null || $tooOld) {
                return [$posts, false, false];
            }
        }

        return [$posts, true, false];
    }

    /**
     * Fetch everything newer than what we already have.
     *
     * @return int new posts
     */
    public function incremental(Channel $channel): int
    {
        $knownNewest = $channel->newest_msg_id;
        $channel->forceFill(['scrape_status' => ScrapeStatus::Incremental])->save();

        $before = null;
        $created = 0;

        for ($i = 0; $i < self::MAX_INCREMENTAL_PAGES; $i++) {
            $page = $this->client->feed($channel->username, $before);

            if (! $page->hasPreview) {
                $channel->forceFill(['availability' => ChannelAvailability::NoPreview])->save();
                break;
            }

            $this->applyFeedHeader($channel, $page);
            $created += $this->upserter->upsert($channel, $page->posts);

            $min = $page->minMsgId();

            if ($min === null || $knownNewest === null || $min <= $knownNewest || $page->olderCursor === null) {
                break;
            }

            $before = $page->olderCursor;
        }

        $channel->forceFill([
            'scrape_status' => ScrapeStatus::Idle,
            'last_scraped_at' => now(),
            'failure_count' => 0,
            'last_error' => null,
        ])->save();

        return $created;
    }

    /**
     * Weekly: refresh views for the last 30 days and flag posts that vanished.
     */
    public function refreshRecentViews(Channel $channel): int
    {
        $cutoff = now()->subDays(30);
        $before = null;
        $seen = [];
        $min = null;
        $max = null;

        for ($i = 0; $i < self::MAX_VIEW_REFRESH_PAGES; $i++) {
            $page = $this->client->feed($channel->username, $before);

            if (! $page->hasPreview || $page->isEmpty()) {
                break;
            }

            $this->upserter->upsert($channel, $page->posts);

            foreach ($page->posts as $post) {
                $seen[] = $post->msgId;
            }

            $min = min($min ?? PHP_INT_MAX, (int) $page->minMsgId());
            $max = max($max ?? 0, (int) $page->maxMsgId());

            $oldest = collect($page->posts)->min(fn ($p) => $p->postedAt?->getTimestamp() ?? PHP_INT_MAX);

            if ($page->olderCursor === null || ($oldest !== null && $oldest < $cutoff->getTimestamp())) {
                break;
            }

            $before = $page->olderCursor;
        }

        if ($min === null || $max === null) {
            return 0;
        }

        return $this->upserter->markMissingAsDeleted($channel, $min, $max, $seen);
    }

    public function backfillLimitReached(Channel $channel, ?FeedPage $page = null): bool
    {
        $maxPosts = (int) config('buildr.scrape.backfill_max_posts');
        $maxMonths = (int) config('buildr.scrape.backfill_max_months');

        if ($channel->posts()->count() >= $maxPosts) {
            return true;
        }

        $oldest = $page?->posts !== null && $page->posts !== []
            ? collect($page->posts)->min(fn ($p) => $p->postedAt)
            : null;

        return $oldest instanceof CarbonImmutable && $oldest->lt(now()->subMonths($maxMonths));
    }

    private function finishBackfill(Channel $channel): bool
    {
        $channel->forceFill([
            'backfill_complete' => true,
            'scrape_status' => ScrapeStatus::Idle,
            'last_scraped_at' => now(),
            'failure_count' => 0,
            'last_error' => null,
        ])->save();

        return false;
    }

    private function applyFeedHeader(Channel $channel, FeedPage $page): void
    {
        $channel->forceFill(array_filter([
            'title' => $channel->title ?? $page->title,
            'availability' => ChannelAvailability::Ok,
            'unavailable_since' => null,
        ], fn ($value) => $value !== null) + ['unavailable_since' => null])->save();
    }

    private function snapshot(Channel $channel, int $subscribers): void
    {
        $exists = ChannelSnapshot::where('channel_id', $channel->id)
            ->where('captured_at', '>=', now()->startOfDay())
            ->exists();

        if (! $exists) {
            ChannelSnapshot::create([
                'channel_id' => $channel->id,
                'subscribers' => $subscribers,
                'captured_at' => now(),
            ]);
        }
    }
}
