<?php

use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\ChannelStat;
use App\Models\Creator;
use App\Models\Project;
use App\Models\User;
use App\Services\Claims\OwnershipActions;
use App\Services\Og\CardStyle;
use App\Services\Og\OgImageRenderer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
});

/**
 * A live creator with one claimed channel.
 *
 * @return array{0: Creator, 1: Channel}
 */
function cardOwnerWithChannel(): array
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->published()->create(['slug' => 'selam', 'display_name' => 'Selam']);
    $channel = Channel::factory()->claimedBy($creator)->create(['username' => 'selambuilds', 'title' => 'Selam Builds']);
    $creator->update(['primary_channel_id' => $channel->id]);

    return [$creator->fresh(), $channel->fresh()];
}

function cardProject(Channel $channel, string $name, ProjectStatus $status, bool $hidden = false): Project
{
    return Project::create([
        'channel_id' => $channel->id, 'name' => $name, 'slug' => str($name)->slug(), 'normalized_name' => strtolower($name),
        'status' => $status, 'source' => ContentSource::Ai, 'hidden' => $hidden,
    ]);
}

it('counts shipped projects live, not from the stats snapshot', function () {
    [, $channel] = cardOwnerWithChannel();
    ChannelStat::create(['channel_id' => $channel->id, 'stats' => ['projects_shipped' => 0, 'projects_count' => 0, 'total_posts' => 40], 'computed_at' => now()]);

    cardProject($channel, 'Chapa Bot', ProjectStatus::Launched);
    cardProject($channel, 'Gebeya', ProjectStatus::Launched);
    cardProject($channel, 'Side Thing', ProjectStatus::Building);
    cardProject($channel, 'Old Launch', ProjectStatus::Launched, hidden: true);

    $this->get('/c/selambuilds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('channel.sections.0.stats.projects_shipped', 2)
        ->where('channel.sections.0.stats.projects_count', 3));
});

it('opens on a light card in the seeded color until the owner picks a look', function () {
    [$creator, $channel] = cardOwnerWithChannel();

    expect(CardStyle::for($creator)->toArray())->toBe(['theme' => 'light', 'color' => OgImageRenderer::tone('selam')]);

    $this->get('/c/selambuilds')->assertInertia(fn (Assert $page) => $page
        ->where('channel.card', ['theme' => 'light', 'color' => OgImageRenderer::tone('selambuilds')]));
});

it('lets the owner keep a look as their link preview', function () {
    [$creator, $channel] = cardOwnerWithChannel();

    $this->actingAs($creator->user)->from('/selam')
        ->put("/dashboard/share-card/creator/{$creator->id}", ['theme' => 'dark', 'color' => 'rose'])
        ->assertRedirect('/selam')
        ->assertSessionHasNoErrors();

    $this->actingAs($creator->user)
        ->put("/dashboard/share-card/channel/{$channel->id}", ['theme' => 'color', 'color' => 'ink'])
        ->assertSessionHasNoErrors();

    expect($creator->fresh())->card_theme->toBe('dark')->card_color->toBe('rose')
        ->and($channel->fresh())->card_theme->toBe('color')->card_color->toBe('ink');

    Storage::disk('public')->assertExists("og/creator/{$creator->id}.png");
    Storage::disk('public')->assertExists("og/channel/{$channel->id}.png");

    $this->get('/selam')->assertInertia(fn (Assert $page) => $page
        ->where('profile.card', ['theme' => 'dark', 'color' => 'rose']));
});

it('only lets the owner change the look, and only to real looks', function () {
    [$creator, $channel] = cardOwnerWithChannel();
    $other = Creator::factory()->for(User::factory()->create())->published()->create(['slug' => 'dawit']);

    $this->actingAs($other->user)
        ->put("/dashboard/share-card/creator/{$creator->id}", ['theme' => 'dark', 'color' => 'rose'])
        ->assertForbidden();
    $this->actingAs($other->user)
        ->put("/dashboard/share-card/channel/{$channel->id}", ['theme' => 'dark', 'color' => 'rose'])
        ->assertForbidden();
    $this->actingAs($creator->user)
        ->put("/dashboard/share-card/creator/{$creator->id}", ['theme' => 'neon', 'color' => 'lime'])
        ->assertSessionHasErrors(['theme', 'color']);

    expect($creator->fresh()->card_theme)->toBeNull();
});

it('renders any look on demand without touching the link preview', function () {
    [$creator] = cardOwnerWithChannel();
    $disk = Storage::disk('public');

    $this->get("/share-card/creator/{$creator->id}.png?theme=dark&color=violet")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $disk->assertExists("og/creator/{$creator->id}.png");
    $disk->assertExists("og/creator/{$creator->id}/dark-violet.png");
    expect($creator->fresh()->card_theme)->toBeNull();

    // Unknown looks fall back to the owner's card.
    $this->get("/share-card/creator/{$creator->id}.png?theme=neon&color=violet")->assertOk();
    expect($disk->files("og/creator/{$creator->id}"))->toHaveCount(1);

    // The owner's own look is the og:image itself, not a copy.
    $tone = OgImageRenderer::tone('selam');
    $this->get("/share-card/creator/{$creator->id}.png?theme=light&color={$tone}")->assertOk();
    $disk->assertMissing("og/creator/{$creator->id}/light-{$tone}.png");
});

it('re-renders a picked look once the owner card is newer', function () {
    [$creator] = cardOwnerWithChannel();
    $disk = Storage::disk('public');
    $variant = "og/creator/{$creator->id}/color-amber.png";

    $this->get("/share-card/creator/{$creator->id}.png?theme=color&color=amber")->assertOk();
    $disk->put($variant, 'stale');
    touch($disk->path($variant), now()->subMinute()->getTimestamp());

    $this->get("/share-card/creator/{$creator->id}.png?theme=color&color=amber")->assertOk();

    expect($disk->get($variant))->not->toBe('stale');
});

it('gives a new owner the default look', function () {
    [$creator, $channel] = cardOwnerWithChannel();
    $channel->update(['card_theme' => 'dark', 'card_color' => 'rose']);

    app(OwnershipActions::class)->transferChannel($channel->fresh(), User::factory()->create());

    expect($channel->fresh())->card_theme->toBeNull()->card_color->toBeNull();
});
