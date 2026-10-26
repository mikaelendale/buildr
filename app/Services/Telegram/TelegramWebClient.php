<?php

namespace App\Services\Telegram;

use App\Models\BlockedUsername;
use App\Models\Setting;
use App\Services\Telegram\Data\ChannelInfo;
use App\Services\Telegram\Data\FeedPage;
use App\Services\Telegram\Exceptions\BlockedUsernameException;
use App\Services\Telegram\Exceptions\ScrapingPausedException;
use App\Services\Telegram\Exceptions\TelegramFetchException;
use App\Support\TelegramUsername;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * The only way the app talks to t.me (plan §7.3).
 *
 * - Hardcoded host: only https://t.me is ever requested, so there is no SSRF surface.
 * - One global rate limit across every worker (a cache lock + last-request stamp).
 * - Honest User-Agent, blocklist + kill switch checked on every request.
 * - Raw HTML kept for 30 days so the parser can be re-run without re-fetching.
 */
class TelegramWebClient
{
    private const BASE_URL = 'https://t.me/';

    private const LOCK_KEY = 'telegram-web:lock';

    private const LAST_REQUEST_KEY = 'telegram-web:last-request';

    public function __construct(
        private readonly ChannelInfoParser $infoParser,
        private readonly ChannelFeedParser $feedParser,
    ) {}

    public function channelInfo(string $username): ChannelInfo
    {
        $username = $this->guard($username);
        $response = $this->get($username);

        return $this->infoParser->parse($username, $response->body());
    }

    /**
     * One page of the public feed. $before pages backwards through history.
     */
    public function feed(string $username, ?int $before = null): FeedPage
    {
        $username = $this->guard($username);
        $path = 's/'.$username.($before !== null ? '?before='.$before : '');
        $response = $this->get($path);

        // Channels with the web preview disabled redirect /s/{u} to /{u}.
        if ($response->redirect()) {
            return FeedPage::noPreview($username);
        }

        return $this->feedParser->parse($username, $response->body());
    }

    /**
     * Several history pages at once (one polite burst instead of N spaced
     * requests). Keys are the `before` cursors; '' is the newest page.
     *
     * @param  list<int|null>  $befores
     * @return array<int|string, FeedPage> numeric cursors become int keys
     */
    public function feedMany(string $username, array $befores): array
    {
        $username = $this->guard($username);

        if (count($befores) === 1) {
            return [(string) $befores[0] => $this->feed($username, $befores[0])];
        }

        $paths = [];

        foreach ($befores as $before) {
            $paths[(string) $before] = 's/'.$username.($before !== null ? '?before='.$before : '');
        }

        /** @var array<string, Response|\Throwable> $responses */
        $responses = Cache::lock(self::LOCK_KEY, 60)->block(120, function () use ($paths) {
            $this->waitForSlot();

            try {
                return Http::pool(fn (Pool $pool) => array_map(
                    fn (string $key) => $pool->as($key)
                        ->withUserAgent((string) config('buildr.scrape.user_agent'))
                        ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
                        ->timeout(20)
                        ->withoutRedirecting()
                        ->get(self::BASE_URL.$paths[$key]),
                    array_map('strval', array_keys($paths)),
                ));
            } finally {
                Cache::put(self::LAST_REQUEST_KEY, microtime(true), 300);
            }
        });

        $pages = [];

        foreach ($paths as $key => $path) {
            $response = $responses[(string) $key] ?? null;

            if (! $response instanceof Response) {
                throw new TelegramFetchException('Connection failed for '.$path);
            }

            if ($response->status() === 429 || $response->serverError()
                || ($response->clientError() && $response->status() !== 404)) {
                throw new TelegramFetchException("t.me returned HTTP {$response->status()}", $response->status());
            }

            $pages[(string) $key] = $response->redirect()
                ? FeedPage::noPreview($username)
                : $this->feedParser->parse($username, $response->body());
        }

        return $pages;
    }

    private function guard(string $username): string
    {
        $username = strtolower($username);

        if (! TelegramUsername::isValid($username)) {
            throw new InvalidArgumentException("Invalid Telegram username [{$username}].");
        }

        if (Setting::flag(Setting::SCRAPING_PAUSED)) {
            throw new ScrapingPausedException;
        }

        if (BlockedUsername::isBlocked($username)) {
            throw new BlockedUsernameException($username);
        }

        return $username;
    }

    private function get(string $path): Response
    {
        return Cache::lock(self::LOCK_KEY, 60)->block(120, function () use ($path) {
            $this->waitForSlot();

            try {
                $response = Http::withUserAgent((string) config('buildr.scrape.user_agent'))
                    ->withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
                    ->timeout(20)
                    ->withoutRedirecting()
                    ->get(self::BASE_URL.$path);
            } catch (ConnectionException $e) {
                throw new TelegramFetchException('Connection failed: '.$e->getMessage());
            } finally {
                Cache::put(self::LAST_REQUEST_KEY, microtime(true), 300);
            }

            if ($response->status() === 429 || $response->serverError()) {
                throw new TelegramFetchException("t.me returned HTTP {$response->status()}", $response->status());
            }

            if ($response->clientError() && $response->status() !== 404) {
                throw new TelegramFetchException("t.me returned HTTP {$response->status()}", $response->status());
            }

            // Keep a copy of info pages and newest feed pages for parser
            // debugging; deep history pages aren't worth the disk.
            if (! str_contains($path, 'before=')) {
                $this->snapshot($path, $response->body());
            }

            return $response;
        });
    }

    /**
     * Enforce ≥ min_delay + random jitter between any two requests, globally.
     */
    private function waitForSlot(): void
    {
        $minDelay = (int) config('buildr.scrape.min_delay_ms');
        $jitter = (int) config('buildr.scrape.jitter_ms');

        if ($minDelay <= 0 && $jitter <= 0) {
            return;
        }

        $last = (float) Cache::get(self::LAST_REQUEST_KEY, 0);
        $gapMs = $minDelay + ($jitter > 0 ? random_int(0, $jitter) : 0);
        $waitMs = (int) ceil(($last + $gapMs / 1000 - microtime(true)) * 1000);

        if ($waitMs > 0) {
            usleep($waitMs * 1000);
        }
    }

    private function snapshot(string $path, string $body): void
    {
        if ($body === '') {
            return;
        }

        $name = preg_replace('/[^a-z0-9_=-]+/i', '_', $path);

        Storage::disk((string) config('buildr.scrape.snapshot_disk'))->put(
            'snapshots/'.now()->format('Y-m-d').'/'.$name.'-'.now()->format('His').'.html',
            $body,
        );
    }
}
