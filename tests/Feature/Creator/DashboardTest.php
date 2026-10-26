<?php

use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelVisibility;
use App\Enums\CreatorStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Summary;
use App\Models\SummaryOverride;
use App\Models\User;
use App\Services\Profiles\SummaryPresenter;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

function creatorWithChannel(array $creator = []): array
{
    $user = User::factory()->create();
    $c = Creator::factory()->for($user)->create($creator + ['slug' => 'selam', 'display_name' => 'Selam']);
    $channel = Channel::factory()->claimedBy($c)->create(['username' => 'selambuilds']);
    $c->update(['primary_channel_id' => $channel->id]);

    Summary::create([
        'channel_id' => $channel->id, 'creator_id' => $c->id, 'version' => 1, 'mode' => SummaryMode::Claimed,
        'status' => SummaryStatus::Verified, 'prompt_version' => 1, 'triggered_by' => 'system',
        'content' => ['hook' => 'AI hook', 'intro' => 'AI intro', 'vibe_tags' => ['Builder']],
        'citations' => ['hook' => [1], 'intro' => [1]],
    ]);

    return [$user, $c, $channel];
}

beforeEach(fn () => Bus::fake());

it('shows the dashboard with the creator status', function () {
    [$user] = creatorWithChannel();

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/index')->where('creator.status', 'draft')->has('channels', 1));
});

it('resumes onboarding at the stored step and blocks skipping ahead', function () {
    [$user, $creator] = creatorWithChannel(['onboarding_step' => 4]);

    $this->actingAs($user)->get('/onboarding')->assertRedirect('/onboarding/basics');
    $this->get('/onboarding/review')->assertRedirect('/onboarding/basics');
    $this->get('/onboarding/verify')->assertOk();
});

it('saves basics, socials and tags during onboarding', function () {
    [$user, $creator] = creatorWithChannel(['onboarding_step' => 4]);

    $this->actingAs($user)->post('/onboarding/basics', [
        'display_name' => 'Selam T',
        'real_name' => 'Selam Tesfaye',
        'real_name_am' => 'ሰላም ተስፋዬ',
        'slug' => 'selam-t',
        'bio' => 'Building invoicing tools.',
        'location' => 'Addis Ababa',
        'timezone' => 'Africa/Addis_Ababa',
        'socials' => [['url' => 'github.com/selam'], ['url' => 'https://x.com/selam']],
        'tags' => ['Web Dev', 'Startups'],
    ])->assertRedirect('/onboarding/building');

    $creator->refresh()->load('socials', 'tags');

    expect($creator->slug)->toBe('selam-t')
        ->and($creator->real_name_am)->toBe('ሰላም ተስፋዬ')
        ->and($creator->onboarding_step)->toBe(5)
        ->and($creator->socials->pluck('platform')->map->value->all())->toBe(['github', 'x'])
        ->and($creator->socials->first()->url)->toBe('https://github.com/selam')
        ->and($creator->tags->pluck('name')->all())->toBe(['Web Dev', 'Startups']);
});

it('rejects reserved and taken slugs', function () {
    [$user] = creatorWithChannel(['onboarding_step' => 4]);
    Creator::factory()->create(['slug' => 'taken-name']);

    $base = ['display_name' => 'S', 'timezone' => 'UTC'];

    $this->actingAs($user)->post('/onboarding/basics', $base + ['slug' => 'admin'])->assertSessionHasErrors('slug');
    $this->post('/onboarding/basics', $base + ['slug' => 'taken-name'])->assertSessionHasErrors('slug');
    $this->post('/onboarding/basics', $base + ['slug' => '-bad'])->assertSessionHasErrors('slug');

    $this->getJson('/api/slug-check?slug=taken-name')->assertJson(['available' => false]);
    $this->getJson('/api/slug-check?slug=fresh-name')->assertJson(['available' => true]);
});

it('submits for review only with a verified channel', function () {
    [$user, $creator] = creatorWithChannel(['onboarding_step' => 6]);

    $this->actingAs($user)->post('/onboarding/submit')->assertRedirect('/dashboard');

    expect($creator->fresh()->status)->toBe(CreatorStatus::PendingReview);

    $lonely = User::factory()->create();
    Creator::factory()->for($lonely)->create(['onboarding_step' => 6]);

    $this->actingAs($lonely)->post('/onboarding/submit')->assertSessionHasErrors('submit');
});

it('keeps name and slug changes pending while a profile is published', function () {
    [$user, $creator] = creatorWithChannel(['status' => CreatorStatus::Published, 'published_at' => now()]);

    $this->actingAs($user)->put('/dashboard/profile', [
        'display_name' => 'New Name',
        'slug' => 'new-slug',
        'bio' => 'Fresh bio',
        'timezone' => 'UTC',
    ])->assertRedirect();

    $creator->refresh();

    expect($creator->display_name)->toBe('Selam')
        ->and($creator->slug)->toBe('selam')
        ->and($creator->bio)->toBe('Fresh bio')
        ->and($creator->pending_changes)->toBe(['display_name' => 'New Name', 'slug' => 'new-slug']);
});

it('edits, hides and resets summary sections without touching the AI version', function () {
    [$user, , $channel] = creatorWithChannel();

    $this->actingAs($user)->put("/dashboard/summary/{$channel->id}/hook", ['value' => 'My own hook'])->assertRedirect();
    $this->post("/dashboard/summary/{$channel->id}/intro/hide", ['hidden' => true])->assertRedirect();

    $presented = app(SummaryPresenter::class)->present($channel->fresh());

    expect($presented['content']['hook'])->toBe('My own hook')
        ->and($presented['content'])->not->toHaveKey('intro')
        ->and(Summary::first()->content['hook'])->toBe('AI hook');

    $this->post("/dashboard/summary/{$channel->id}/hook/reset")->assertRedirect();

    expect(SummaryOverride::where('section_key', 'hook')->exists())->toBeFalse();
});

it('limits section regenerations per day', function () {
    config(['buildr.regen.creator_daily_limit' => 1]);
    [$user, , $channel] = creatorWithChannel();

    Summary::create([
        'channel_id' => $channel->id, 'version' => 2, 'mode' => 'claimed', 'status' => 'verified',
        'prompt_version' => 1, 'triggered_by' => 'creator', 'content' => [],
    ]);

    $this->actingAs($user)->post("/dashboard/summary/{$channel->id}/hook/regenerate")->assertSessionHasErrors('section');
});

it('forbids editing another creator\'s channel', function () {
    [, , $channel] = creatorWithChannel();
    $intruder = User::factory()->create();
    Creator::factory()->for($intruder)->create();

    $this->actingAs($intruder)->put("/dashboard/summary/{$channel->id}/hook", ['value' => 'x'])->assertForbidden();
});

it('adds, edits, hides and deletes projects', function () {
    [$user, , $channel] = creatorWithChannel();

    $this->actingAs($user)->post('/dashboard/projects', [
        'channel_id' => $channel->id, 'name' => 'Birr Invoice', 'url' => 'https://birr.example', 'status' => 'launched',
    ])->assertRedirect();

    $project = $channel->projects()->firstOrFail();
    expect($project->source->value)->toBe('creator');

    $this->post("/dashboard/projects/{$project->id}/toggle")->assertRedirect();
    expect($project->fresh()->hidden)->toBeTrue();

    $this->delete("/dashboard/projects/{$project->id}")->assertRedirect();
    expect($channel->projects()->count())->toBe(0);
});

it('removes a channel from the dashboard after typing its name', function () {
    [$user, , $channel] = creatorWithChannel();

    $this->actingAs($user)->delete("/dashboard/channels/{$channel->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    $this->delete("/dashboard/channels/{$channel->id}", ['confirm' => 'selambuilds'])->assertRedirect('/dashboard/channels');

    expect($channel->fresh()->visibility)->toBe(ChannelVisibility::Removed)
        ->and(BlockedUsername::isBlocked('selambuilds'))->toBeTrue();
});

it('deletes an account and can keep channels as unclaimed pages', function () {
    [$user, $creator, $channel] = creatorWithChannel();

    $this->actingAs($user)->delete('/dashboard/settings/account', ['confirm' => 'selam', 'keep_channels' => true])->assertRedirect('/');

    $this->assertGuest();
    expect(Creator::find($creator->id))->toBeNull()
        ->and($channel->fresh()->claim_status)->toBe(ChannelClaimStatus::Unclaimed)
        ->and($channel->fresh()->visibility)->toBe(ChannelVisibility::Hidden)
        ->and($user->fresh()->telegram_id)->toBeNull();
});
