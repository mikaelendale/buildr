<?php

use App\Enums\ChannelAvailability;
use App\Enums\ScrapeStatus;
use App\Enums\TelegramPageType;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Media\StoreChannelAvatar;
use App\Jobs\Scrape\BackfillChannelPage;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Jobs\Scrape\IncrementalScrape;
use App\Jobs\Scrape\RunScraperCanary;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\ChannelSnapshot;
use App\Models\DirectoryEntry;
use App\Models\Post;
use App\Models\Setting;
use App\Services\Directory\DirectoryIndexer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function tinyJpeg(): string
{
    $img = imagecreatetruecolor(10, 10);
    ob_start();
    imagejpeg($img);

    return (string) ob_get_clean();
}

function fakeTelegram(array $overrides = []): void
{
    Http::fake($overrides + [
        't.me/s/testbuilder?before=100' => Http::response('<html><body></body></html>'),
        't.me/s/testbuilder*' => Http::response(fixtureContents('telegram/feed-synthetic.html')),
        't.me/testbuilder' => Http::response(fixtureContents('telegram/channel-info-durov.html')),
        '*.telesco.pe/*' => Http::response(tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Bus::fake([ProcessChannel::class]);
});

it('fetches channel info, backfills posts and hands off to the AI pipeline', function () {
    fakeTelegram();
    $channel = Channel::factory()->create(['username' => 'testbuilder', 'title' => null]);

    FetchChannelInfo::dispatchSync($channel->id, true);

    $channel->refresh();

    expect($channel->page_type)->toBe(TelegramPageType::Channel)
        ->and($channel->availability)->toBe(ChannelAvailability::Ok)
        ->and($channel->subscribers)->toBe(10_249_235)
        ->and($channel->backfill_complete)->toBeTrue()
        ->and($channel->scrape_status)->toBe(ScrapeStatus::Idle)
        ->and($channel->newest_msg_id)->toBe(106)
        ->and($channel->oldest_msg_id)->toBe(100)
        ->and($channel->avatar_path)->not->toBeNull()
        ->and(Post::where('channel_id', $channel->id)->count())->toBe(6)
        ->and(ChannelSnapshot::where('channel_id', $channel->id)->count())->toBe(1);

    $post = Post::where('channel_id', $channel->id)->where('msg_id', 100)->first();
    expect($post->links)->toHaveCount(1)
        ->and($post->links->first()->kind->value)->toBe('github')
        ->and($post->lang->value)->toBe('en');

    Storage::disk('public')->assertExists($channel->avatar_path);
    Bus::assertDispatched(ProcessChannel::class, fn ($job) => $job->channelId === $channel->id);
});

it('never scrapes blocklisted usernames, even when a job is already queued', function () {
    fakeTelegram();
    $channel = Channel::factory()->create(['username' => 'testbuilder']);
    BlockedUsername::block('testbuilder', 'Owner asked');

    FetchChannelInfo::dispatchSync($channel->id, true);

    Http::assertNothingSent();
    expect(Post::count())->toBe(0);
});

it('marks channels without a web preview', function () {
    Http::fake([
        't.me/s/nopreview*' => Http::response('', 302, ['Location' => 'https://t.me/nopreview']),
    ]);
    $channel = Channel::factory()->create(['username' => 'nopreview']);

    BackfillChannelPage::dispatchSync($channel->id);

    expect($channel->refresh()->availability)->toBe(ChannelAvailability::NoPreview)
        ->and($channel->backfill_complete)->toBeTrue();
});

it('marks usernames that do not exist', function () {
    Http::fake(['t.me/ghostchannel' => Http::response(fixtureContents('telegram/not-found.html'))]);
    $channel = Channel::factory()->create(['username' => 'ghostchannel']);

    FetchChannelInfo::dispatchSync($channel->id);

    expect($channel->refresh()->availability)->toBe(ChannelAvailability::NotFound)
        ->and($channel->unavailable_since)->not->toBeNull();
});

it('counts consecutive failures and gives up after five', function () {
    Http::fake(['t.me/*' => Http::response('slow down', 429)]);
    $channel = Channel::factory()->create(['username' => 'testbuilder', 'failure_count' => 4]);

    FetchChannelInfo::dispatchSync($channel->id);

    expect($channel->refresh()->failure_count)->toBe(5)
        ->and($channel->scrape_status)->toBe(ScrapeStatus::Failed)
        ->and($channel->last_error)->toContain('429');
});

it('respects the global kill switch', function () {
    Http::fake();
    Setting::write(Setting::SCRAPING_PAUSED, true);
    $channel = Channel::factory()->create(['username' => 'testbuilder']);

    FetchChannelInfo::dispatchSync($channel->id);

    Http::assertNothingSent();
});

it('picks up new posts incrementally without duplicating old ones', function () {
    fakeTelegram();
    $channel = Channel::factory()->create(['username' => 'testbuilder', 'backfill_complete' => true]);
    Post::factory()->for($channel)->create(['msg_id' => 100, 'text' => 'old text']);
    $channel->update(['newest_msg_id' => 100]);

    IncrementalScrape::dispatchSync($channel->id);

    expect(Post::where('channel_id', $channel->id)->count())->toBe(6)
        ->and(Post::where('channel_id', $channel->id)->where('msg_id', 100)->value('text'))->toContain('Launched Chapa Bot');
});

it('pauses scraping when the canary parses nothing', function () {
    config(['buildr.scrape.canary_channel' => 'testbuilder']);
    fakeTelegram(['t.me/s/testbuilder*' => Http::response('<html><body>changed markup</body></html>')]);

    RunScraperCanary::dispatchSync();

    expect(Setting::flag(Setting::SCRAPING_PAUSED))->toBeTrue()
        ->and(Setting::read(Setting::CANARY_STATUS)['ok'])->toBeFalse();
});

it('keeps scraping running when the canary is healthy', function () {
    config(['buildr.scrape.canary_channel' => 'testbuilder']);
    fakeTelegram();

    RunScraperCanary::dispatchSync();

    expect(Setting::flag(Setting::SCRAPING_PAUSED))->toBeFalse()
        ->and(Setting::read(Setting::CANARY_STATUS)['ok'])->toBeTrue();
});

/**
 * A synthetic t.me feed page: the 20 newest posts below $before (newest last),
 * ids 1..$total, one post per hour.
 */
function syntheticFeedPage(string $username, int $before, int $total): string
{
    $ids = range(max(1, $before - 20), min($total, $before - 1));
    $messages = collect($ids)->map(fn (int $id) => sprintf(
        '<div class="tgme_widget_message_wrap"><div class="tgme_widget_message js-widget_message" data-post="%1$s/%2$d"><div class="tgme_widget_message_bubble"><div class="tgme_widget_message_text js-message_text">Post %2$d shipping notes</div><div class="tgme_widget_message_footer"><div class="tgme_widget_message_info"><span class="tgme_widget_message_views">10</span><span class="tgme_widget_message_meta"><a class="tgme_widget_message_date" href="https://t.me/%1$s/%2$d"><time datetime="%3$s" class="time">x</time></a></span></div></div></div></div></div>',
        $username,
        $id,
        now()->subHours($total - $id)->toIso8601String(),
    ))->implode('');
    $more = $ids !== [] && $ids[0] > 1
        ? sprintf('<a href="/s/%s?before=%d" class="tme_messages_more js-messages_more" data-before="%d"></a>', $username, $ids[0], $ids[0])
        : '';

    return '<html><body><section class="tgme_channel_history">'.$more.$messages.'</section></body></html>';
}

it('backfills in parallel bursts and stops at the newest 3,000 posts', function () {
    config(['buildr.scrape.backfill_max_posts' => 3000, 'buildr.scrape.backfill_max_months' => 120, 'buildr.scrape.concurrency' => 4, 'buildr.scrape.pages_per_job' => 400]);
    $total = 5000;
    $requests = 0;

    Http::fake(function ($request) use ($total, &$requests) {
        $requests++;
        preg_match('/before=(\d+)/', $request->url(), $m);

        return Http::response(syntheticFeedPage('bigchannel', isset($m[1]) ? (int) $m[1] : $total + 1, $total));
    });

    $channel = Channel::factory()->create(['username' => 'bigchannel']);

    BackfillChannelPage::dispatchSync($channel->id, false);

    $ids = Post::where('channel_id', $channel->id)->pluck('msg_id');

    expect($ids)->toHaveCount(3000)
        ->and($ids->max())->toBe($total)
        ->and($ids->min())->toBe($total - 2999)
        ->and($channel->refresh()->backfill_complete)->toBeTrue()
        ->and($requests)->toBeLessThanOrEqual(152);
});

it('shows a channel photo that arrives after the channel was listed', function () {
    fakeTelegram();
    $channel = Channel::factory()->published()->create(['username' => 'lateavatar', 'avatar_path' => null]);
    app(DirectoryIndexer::class)->refreshChannel($channel);

    StoreChannelAvatar::dispatchSync($channel->id, 'https://cdn4.telesco.pe/file/photo.jpg');

    $path = $channel->refresh()->avatar_path;
    expect($path)->not->toBeNull()
        ->and(DirectoryEntry::where('listable_id', $channel->id)->value('avatar_path'))->toBe($path);
});
