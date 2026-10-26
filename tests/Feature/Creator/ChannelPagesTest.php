<?php

use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\ProfileView;
use App\Models\Summary;
use App\Models\User;
use App\Services\Claims\OwnershipActions;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
});

/**
 * A live creator with a main channel and a second one, both written up.
 *
 * @return array{0: Creator, 1: Channel, 2: Channel}
 */
function creatorWithTwoChannels(): array
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->published()->create(['slug' => 'selam', 'display_name' => 'Selam']);
    $main = Channel::factory()->claimedBy($creator)->create(['username' => 'selambuilds', 'title' => 'Selam Builds']);
    $labs = Channel::factory()->claimedBy($creator)->create(['username' => 'selam_labs', 'title' => 'Selam Labs']);
    $creator->update(['primary_channel_id' => $main->id]);

    foreach ([$main, $labs] as $channel) {
        Summary::create([
            'channel_id' => $channel->id, 'creator_id' => $creator->id, 'version' => 1, 'mode' => SummaryMode::Claimed,
            'status' => SummaryStatus::Verified, 'prompt_version' => 1, 'triggered_by' => 'system',
            'content' => ['hook' => "{$channel->title} ships weekly.", 'intro' => 'Builds tools.', 'vibe_tags' => ['Builder']],
            'citations' => ['hook' => [1], 'intro' => [1]],
        ]);
    }

    app(DirectoryIndexer::class)->refreshCreator($creator);

    return [$creator, $main->fresh(), $labs->fresh()];
}

/**
 * @param  array<string, mixed>  $settings
 */
function savePage(Creator $creator, Channel $channel, array $settings): TestResponse
{
    return test()->actingAs($creator->user)
        ->from('/dashboard/channels')
        ->put("/dashboard/channels/{$channel->id}/page", $settings + ['slug' => null, 'visible' => true, 'show_owner' => true]);
}

it('lists each channel as its own entry and links them from the profile', function () {
    [$creator, $main, $labs] = creatorWithTwoChannels();

    expect(DirectoryEntry::where('listable_type', 'creator')->count())->toBe(0)
        ->and(DirectoryEntry::where('creator_id', $creator->id)->pluck('url_path')->sort()->values()->all())
        ->toBe(['/c/selam_labs', '/c/selambuilds']);

    $this->get('/selam')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/creator')
        ->has('profile.channel_cards', 2)
        ->where('profile.channel_cards.0.username', 'selambuilds')
        ->where('profile.channel_cards.0.is_primary', true)
        ->where('profile.channel_cards.1.url', url('/c/selam_labs')));

    $this->get('/c/selam_labs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/channel')
        ->where('channel.hook', 'Selam Labs ships weekly.')
        ->where('channel.owner.slug', 'selam')
        ->has('channel.more_channels', 1)
        ->where('channel.more_channels.0.username', 'selambuilds'));
});

it('serves a claimed channel at its short link and forwards /c/ there', function () {
    [$creator, $main] = creatorWithTwoChannels();

    savePage($creator, $main, ['slug' => 'Selam-Builds'])->assertRedirect('/dashboard/channels')->assertSessionHasNoErrors();

    expect($main->fresh()->slug)->toBe('selam-builds')
        ->and(DirectoryEntry::where('listable_id', $main->id)->value('url_path'))->toBe('/selam-builds');

    $this->get('/c/selambuilds')->assertStatus(301)->assertRedirect(url('/selam-builds'));

    $this->get('/selam-builds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/channel')
        ->where('channel.username', 'selambuilds')
        ->where('channel.url', url('/selam-builds'))
        ->where('seo.canonical', url('/selam-builds')));

    // Clearing it goes back to /c/{username}.
    savePage($creator, $main, ['slug' => ''])->assertSessionHasNoErrors();
    expect($main->fresh()->slug)->toBeNull();
    $this->get('/c/selambuilds')->assertOk();
    $this->get('/selam-builds')->assertNotFound();
});

it('rejects short links that are taken, reserved or malformed', function () {
    [$creator, $main, $labs] = creatorWithTwoChannels();
    $labs->update(['slug' => 'selam-labs']);

    savePage($creator, $main, ['slug' => 'selam'])->assertSessionHasErrors('slug');
    savePage($creator, $main, ['slug' => 'selam-labs'])->assertSessionHasErrors('slug');
    savePage($creator, $main, ['slug' => 'discover'])->assertSessionHasErrors('slug');
    savePage($creator, $main, ['slug' => '-x'])->assertSessionHasErrors('slug');

    $this->actingAs($creator->user)->getJson("/api/slug-check?slug=selam&channel={$main->id}")->assertJsonPath('available', false);
    $this->actingAs($creator->user)->getJson("/api/slug-check?slug=selam-labs&channel={$labs->id}")->assertJsonPath('available', true);
    $this->actingAs($creator->user)->getJson("/api/slug-check?slug=fresh-name&channel={$main->id}")->assertJsonPath('available', true);

    // Creator links and channel links share one namespace.
    $this->actingAs($creator->user)->getJson('/api/slug-check?slug=selam-labs')->assertJsonPath('available', false);

    // Only the owner can change a channel's page.
    $other = Creator::factory()->for(User::factory())->published()->create(['slug' => 'dawit']);
    savePage($other, $main, ['slug' => 'mine-now'])->assertForbidden();
});

it('keeps nameless and hidden channels off the profile and out of search', function () {
    [$creator, $main, $labs] = creatorWithTwoChannels();
    $creator->update(['real_name' => 'Selam Tesfaye']);
    app(DirectoryIndexer::class)->refreshCreator($creator);

    savePage($creator, $labs, ['show_owner' => false])->assertSessionHasNoErrors();

    $this->get('/c/selam_labs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('channel.claimed', true)
        ->where('channel.owner', null)
        ->has('channel.more_channels', 0));

    $this->get('/selam')->assertInertia(fn (Assert $page) => $page->has('profile.channel_cards', 1));

    $entry = DirectoryEntry::where('listable_id', $labs->id)->first();
    expect($entry->owner)->toBeNull()
        ->and($entry->creator_id)->toBeNull()
        // The owner's name only finds the channels they put it on.
        ->and(app(SearchService::class)->search('Tesfaye')->pluck('listable_id')->all())->toBe([$main->id]);

    savePage($creator, $main, ['visible' => false])->assertSessionHasNoErrors();

    expect(DirectoryEntry::where('listable_id', $main->id)->exists())->toBeFalse();

    auth()->logout();
    $this->get('/c/selambuilds')->assertNotFound();
    $this->actingAs($creator->user)->get('/c/selambuilds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('isPreview', true)
        ->where('isOwner', true));
});

it('counts views across the profile and every channel page', function () {
    [$creator, $main, $labs] = creatorWithTwoChannels();

    ProfileView::create(['viewable_type' => 'creator', 'viewable_id' => $creator->id, 'date' => now()->toDateString(), 'views' => 3, 'unique_views' => 2]);
    ProfileView::create(['viewable_type' => 'channel', 'viewable_id' => $main->id, 'date' => now()->toDateString(), 'views' => 4, 'unique_views' => 3]);
    ProfileView::create(['viewable_type' => 'channel', 'viewable_id' => $labs->id, 'date' => now()->subDay()->toDateString(), 'views' => 5, 'unique_views' => 5]);

    $this->actingAs($creator->user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('views.last7', 12)
        ->where('views.unique30', 10)
        ->has('views.daily', 2)
        ->where('views.daily.1.views', 7));
});

it('resets the short link when a channel changes hands', function () {
    [$creator, $main] = creatorWithTwoChannels();
    $main->update(['slug' => 'selam-builds', 'show_owner' => false]);

    app(OwnershipActions::class)->transferChannel($main->fresh(), User::factory()->create());

    expect($main->fresh())
        ->slug->toBeNull()
        ->show_owner->toBeTrue()
        ->page_visible->toBeTrue();
});
