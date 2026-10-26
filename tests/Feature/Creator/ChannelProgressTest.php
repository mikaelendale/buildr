<?php

use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Bus::fake());

/**
 * A creator whose first channel was read and written a week ago.
 *
 * @return array{0: User, 1: Creator, 2: Channel}
 */
function creatorWithReadyChannel(): array
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->create(['slug' => 'selam', 'display_name' => 'Selam']);
    $first = Channel::factory()->claimedBy($creator)->create(['username' => 'selambuilds', 'backfill_complete' => true, 'last_processed_at' => now()->subWeek()]);
    $creator->update(['primary_channel_id' => $first->id]);

    return [$user, $creator, $first];
}

/**
 * A second channel the creator verified a few minutes ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function secondChannel(Creator $creator, array $attributes = []): Channel
{
    $channel = Channel::factory()->claimedBy($creator)->create($attributes + ['username' => 'selamlabs']);

    Claim::create([
        'user_id' => $creator->user_id, 'channel_id' => $channel->id, 'channel_username' => $channel->username,
        'purpose' => ClaimPurpose::Claim, 'code' => 'BUILDR-'.$channel->id, 'status' => ClaimStatus::Verified,
        'verified_at' => now()->subMinutes(5), 'expires_at' => now()->addDay(),
    ]);

    return $channel;
}

it('tracks a second channel while it is being read, and skips finished ones', function () {
    [$user, $creator] = creatorWithReadyChannel();

    $second = secondChannel($creator, ['backfill_complete' => false, 'last_processed_at' => null]);
    Post::factory()->for($second)->count(3)->create();

    $response = $this->actingAs($user)->getJson('/api/channels/progress')->assertOk();

    $channels = $response->json('channels');

    expect($channels)->toHaveCount(1)
        ->and($channels[0]['channel']['username'])->toBe('selamlabs')
        ->and($channels[0]['ready'])->toBeFalse()
        ->and($channels[0]['stages'][0]['key'])->toBe('scraping')
        ->and($channels[0]['stages'][0]['detail'])->toBe('3 posts');
});

it('shows a just-finished channel as ready for a while', function () {
    [$user, $creator] = creatorWithReadyChannel();
    secondChannel($creator, ['backfill_complete' => true, 'last_processed_at' => now()->subMinute()]);

    $channels = $this->actingAs($user)->getJson('/api/channels/progress')->json('channels');

    expect($channels)->toHaveCount(1)
        ->and($channels[0]['ready'])->toBeTrue()
        ->and($channels[0]['finished_recently'])->toBeTrue();
});

it('passes live progress to the dashboard and channels pages', function () {
    [$user, $creator] = creatorWithReadyChannel();
    secondChannel($creator, ['backfill_complete' => false, 'last_processed_at' => null]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/index')
            ->has('building', 1)
            ->where('building.0.channel.username', 'selamlabs'));

    $this->actingAs($user)->get('/dashboard/channels')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/channels')
            ->has('building', 1));
});
