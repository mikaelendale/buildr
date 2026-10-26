<?php

use App\Enums\CreatorStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Models\Channel;
use App\Models\ChannelSuggestion;
use App\Models\Creator;
use App\Models\FeaturedItem;
use App\Models\ProfileView;
use App\Models\Report;
use App\Models\Summary;
use App\Models\Tag;
use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function publishedCreator(string $slug, string $name, string $channel, array $extra = []): Creator
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->published()->create(['slug' => $slug, 'display_name' => $name] + $extra);
    $ch = Channel::factory()->claimedBy($creator)->create(['username' => $channel, 'title' => ucfirst($channel), 'subscribers' => 5000]);
    $creator->update(['primary_channel_id' => $ch->id]);

    Summary::create([
        'channel_id' => $ch->id, 'creator_id' => $creator->id, 'version' => 1, 'mode' => SummaryMode::Claimed,
        'status' => SummaryStatus::Verified, 'prompt_version' => 1, 'triggered_by' => 'system',
        'content' => ['hook' => "{$name} ships every week.", 'intro' => 'Builds Laravel tools.', 'vibe_tags' => ['Builder']],
        'citations' => ['hook' => [1], 'intro' => [1]],
    ]);

    app(DirectoryIndexer::class)->refreshCreator($creator);

    return $creator;
}

function publishedChannel(string $username, string $title): Channel
{
    $channel = Channel::factory()->published()->create(['username' => $username, 'title' => $title, 'subscribers' => 70_000]);
    app(DirectoryIndexer::class)->refreshChannel($channel);

    return $channel;
}

beforeEach(fn () => Storage::fake('public'));

it('counts home page visitors once a day each, ignoring refreshes and bots', function () {
    $this->get('/', ['User-Agent' => 'Mozilla/5.0 Chrome/130'])->assertOk();
    $this->get('/', ['User-Agent' => 'Mozilla/5.0 Chrome/130'])->assertOk();
    $this->get('/', ['User-Agent' => 'TelegramBot (like TwitterBot)'])->assertOk();

    $this->get('/', ['User-Agent' => 'Mozilla/5.0 Firefox/140'])
        ->assertInertia(fn (Assert $page) => $page->where('counts.visitors', 2));
});

it('renders the landing page with featured and trending profiles', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');
    FeaturedItem::create(['featurable_type' => 'creator', 'featurable_id' => $creator->id]);

    // A featured creator shows as their main channel, credited to them.
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/home')
        ->has('featured', 1)
        ->where('featured.0.name', 'Selambuilds')
        ->where('featured.0.owner.name', 'Selam')
        ->has('trending', 1));
});

it('ranks exact handles, names and Amharic names first', function () {
    publishedCreator('selam', 'Selam', 'selambuilds', ['real_name' => 'Selam Tesfaye', 'real_name_am' => 'ሰላም ተስፋዬ']);
    publishedCreator('dawit', 'Dawit', 'dawit_ai');
    publishedChannel('selamnews', 'Selam News');

    $search = app(SearchService::class);

    // Listings are channels; the owner's names find their channels.
    expect($search->search('@selambuilds')->first()->name)->toBe('Selambuilds')
        ->and($search->search('https://t.me/dawit_ai')->first()->owner['name'])->toBe('Dawit')
        ->and($search->search('Selam Tesfaye')->first()->name)->toBe('Selambuilds')
        ->and($search->search('ሰላም')->first()->owner['name'])->toBe('Selam')
        ->and($search->search('selam news')->first()->name)->toBe('Selam News')
        ->and($search->search('zzzz-nothing')->count())->toBe(0);
});

it('serves the search page and autocomplete', function () {
    publishedCreator('selam', 'Selam', 'selambuilds');

    $this->get('/search?q=selam')->assertOk()->assertInertia(fn (Assert $page) => $page->component('public/search')->has('results', 1));
    $this->getJson('/api/search/suggest?q=sel')->assertOk()->assertJsonPath('results.0.url', '/c/selambuilds');
    $this->get('/search?q=nobody-here')->assertInertia(fn (Assert $page) => $page->has('results', 0));
});

it('filters discover by tag, claimed, size and language', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');
    publishedChannel('bignews', 'Big News');
    $tag = Tag::create(['slug' => 'ai', 'name' => 'AI', 'is_curated' => true]);
    $creator->tags()->attach($tag->id, ['source' => 'creator']);

    $this->get('/discover')->assertInertia(fn (Assert $page) => $page->component('public/discover')->has('entries.data', 2));
    $this->get('/discover?claimed=1')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->get('/discover?size=50k%2B')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.name', 'Big News'));
    $this->get('/discover?tags[]=ai')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    $this->get('/tags/ai')->assertInertia(fn (Assert $page) => $page->component('public/tag')->has('entries.data', 1));
});

it('shows published creator profiles and hides drafts from the public', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');

    $this->get('/selam')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/creator')
        ->where('profile.hook', 'Selam ships every week.')
        ->where('seo.noindex', false)
        ->has('profile.channel_cards', 1)
        ->where('profile.channel_cards.0.url', url('/c/selambuilds'))
        ->has('profile.sections', 0));

    $creator->update(['status' => CreatorStatus::Draft]);
    $this->get('/selam')->assertNotFound();

    $this->actingAs($creator->user)->get('/selam')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isPreview', true));
});

it('gives claimed channels their own page, credited to the owner', function () {
    publishedCreator('selam', 'Selam', 'selambuilds');

    $this->get('/c/selambuilds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/channel')
        ->where('channel.claimed', true)
        ->where('channel.owner.name', 'Selam')
        ->where('channel.owner.url', url('/selam'))
        ->where('seo.canonical', url('/c/selambuilds')));
});

it('shows unclaimed channel pages with the claim banner data', function () {
    publishedChannel('bignews', 'Big News');

    $this->get('/c/bignews')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/channel')
        ->where('channel.username', 'bignews'));

    Channel::factory()->create(['username' => 'hiddenone']);
    $this->get('/c/hiddenone')->assertNotFound();
});

it('never lets the slug route shadow real routes', function () {
    $this->get('/discover')->assertInertia(fn (Assert $page) => $page->component('public/discover'));
    $this->get('/about')->assertInertia(fn (Assert $page) => $page->component('public/about'));
    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
    $this->get('/robots.txt')->assertOk()->assertSee('User-agent');
    $this->get('/no-such-person')->assertNotFound();
});

it('records reports and rate limits them', function () {
    $channel = publishedChannel('bignews', 'Big News');

    $this->post('/reports', ['target_type' => 'channel', 'target_id' => $channel->id, 'reason' => 'wrong_info', 'details' => 'Old info'])
        ->assertRedirect();

    expect(Report::count())->toBe(1);

    foreach (range(1, 5) as $i) {
        $this->post('/reports', ['target_type' => 'channel', 'target_id' => $channel->id, 'reason' => 'spam']);
    }

    expect(Report::count())->toBe(5);
});

it('counts views and unique visitors without storing IPs', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');

    $this->withHeader('User-Agent', 'Mozilla/5.0 Test')->get('/selam');
    $this->withHeader('User-Agent', 'Mozilla/5.0 Test')->get('/selam');
    $this->withHeader('User-Agent', 'TelegramBot (like TwitterBot)')->get('/selam');

    $view = ProfileView::where('viewable_type', 'creator')->where('viewable_id', $creator->id)->first();

    expect($view->views)->toBe(2)
        ->and($view->unique_views)->toBe(1);
});

it('accepts channel suggestions from the empty search state', function () {
    $this->post('/channel-suggestions', ['username' => 't.me/somechannel'])->assertRedirect();
    $this->post('/channel-suggestions', ['username' => 'not a handle!'])->assertSessionHasErrors('username');

    expect(ChannelSuggestion::where('username', 'somechannel')->exists())->toBeTrue();
});

it('renders OG images on demand', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');

    $this->get("/og/creator/{$creator->id}.png")->assertOk()->assertHeader('Content-Type', 'image/png');
    Storage::disk('public')->assertExists("og/creator/{$creator->id}.png");
});

it('serves the share card on demand, as a download when asked', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');

    $this->get("/share-card/creator/{$creator->id}.png")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
    Storage::disk('public')->assertExists("og/creator/{$creator->id}.png");

    expect($this->get("/share-card/creator/{$creator->id}.png?download=1")->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('buildr-selam.png');
});

it('re-renders every public share card on demand', function () {
    $creator = publishedCreator('selam', 'Selam', 'selambuilds');
    $channel = publishedChannel('openchannel', 'Open Channel');
    $hidden = Channel::factory()->create(['username' => 'hiddenchannel']);

    $this->artisan('buildr:reindex', ['--cards' => true])
        ->expectsOutputToContain('Rendered 3 share cards.')
        ->assertSuccessful();

    Storage::disk('public')->assertExists("og/creator/{$creator->id}.png");
    Storage::disk('public')->assertExists("og/channel/{$channel->id}.png");
    Storage::disk('public')->assertMissing("og/channel/{$hidden->id}.png");
});

it('only lets the owner fetch the share card of a draft profile', function () {
    $creator = publishedCreator('dawit', 'Dawit', 'dawit_ai');
    $creator->update(['status' => CreatorStatus::Draft]);

    $this->get("/share-card/creator/{$creator->id}.png")->assertNotFound();
    $this->actingAs(User::factory()->create())->get("/share-card/creator/{$creator->id}.png")->assertNotFound();
    $this->actingAs($creator->user)->get("/share-card/creator/{$creator->id}.png?fresh=1")->assertOk();
});

it('pages through discover with a cursor once there is more than one page', function () {
    foreach (range(1, 30) as $i) {
        publishedChannel("busychannel{$i}", "Busy Channel {$i}");
    }

    foreach (['trending', 'newest', 'subscribers', 'active'] as $sort) {
        $first = $this->get("/discover?sort={$sort}")->assertOk();
        $cursor = $first->viewData('page')['props']['entries']['meta']['next_cursor'] ?? $first->viewData('page')['props']['entries']['next_cursor'] ?? null;

        expect($cursor)->not->toBeNull();

        $this->get("/discover?sort={$sort}&cursor={$cursor}")->assertOk();
    }
});
