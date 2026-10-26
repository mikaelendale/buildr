<?php

use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Summary;
use App\Models\User;
use App\Services\Claims\ClaimService;
use App\Services\Creators\OnboardingProgress;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * Fake t.me with a channel description that may contain a claim code.
 */
function fakeChannelPages(string $username, string $description = 'Shipping weekly.', string $feedText = 'Hello'): void
{
    $info = str_replace('Founder of Telegram.', $description, fixtureContents('telegram/channel-info-durov.html'));
    $feed = str_replace('Launched <b>Chapa Bot</b> v1 today!', $feedText, fixtureContents('telegram/feed-synthetic.html'));

    // Http::fake() appends stubs; start from a fresh factory so new pages win.
    Http::swap(new HttpFactory);

    Http::fake([
        "t.me/s/{$username}*" => Http::response($feed),
        "t.me/{$username}" => Http::response($info),
    ]);
}

beforeEach(function () {
    Bus::fake([FetchChannelInfo::class, ProcessChannel::class]);
});

it('generates codes in the BUILDR-XXXXXX format', function () {
    expect(ClaimService::generateCode())->toMatch('/^BUILDR-[0-9A-HJKMNP-TV-Z]{6}$/');
});

it('runs the onboarding claim flow from handle to verified channel', function () {
    $user = User::factory()->create();
    fakeChannelPages('mychannel');

    $this->actingAs($user)->post('/onboarding/welcome')->assertRedirect('/onboarding/channel');

    $this->post('/onboarding/channel', ['username' => 'https://t.me/MyChannel'])->assertRedirect('/onboarding/verify');

    $claim = Claim::where('user_id', $user->id)->firstOrFail();
    expect($claim->channel_username)->toBe('mychannel')
        ->and($claim->status)->toBe(ClaimStatus::Pending)
        ->and($user->creator->fresh()->onboarding_step)->toBe(3);

    // Not there yet.
    $this->postJson("/api/claims/{$claim->id}/check")->assertOk()->assertJson(['verified' => false]);

    // The code shows up in the description.
    fakeChannelPages('mychannel', "Shipping weekly. {$claim->code}");
    $this->postJson("/api/claims/{$claim->id}/check")->assertOk()->assertJson(['verified' => true]);

    $channel = Channel::where('username', 'mychannel')->first();

    expect($channel->claim_status)->toBe(ChannelClaimStatus::Claimed)
        ->and($channel->creator_id)->toBe($user->creator->id)
        ->and($user->creator->fresh()->primary_channel_id)->toBe($channel->id)
        ->and($user->creator->fresh()->onboarding_step)->toBe(4)
        ->and($claim->fresh()->found_in)->toBe('description');

    Bus::assertDispatched(FetchChannelInfo::class);
});

it('keeps an existing profile when its channel is claimed: no rescrape, no AI run', function () {
    $channel = Channel::factory()->published()->create(['username' => 'listedchannel', 'backfill_complete' => true]);
    Summary::create([
        'channel_id' => $channel->id, 'version' => 3, 'mode' => SummaryMode::Unclaimed, 'status' => SummaryStatus::Approved,
        'prompt_version' => 1, 'triggered_by' => 'system',
        'content' => ['hook' => 'Ships every week.'], 'citations' => ['hook' => [1]],
    ]);

    $user = User::factory()->create();
    Creator::factory()->for($user)->create(['onboarding_step' => 3]);
    $claim = Claim::create([
        'user_id' => $user->id, 'channel_username' => 'listedchannel', 'purpose' => ClaimPurpose::Claim,
        'code' => 'BUILDR-ABC123', 'status' => ClaimStatus::Pending, 'expires_at' => now()->addDay(),
    ]);
    fakeChannelPages('listedchannel', 'Shipping weekly. BUILDR-ABC123');

    app(ClaimService::class)->check($claim);

    $summary = $channel->summaries()->latest('version')->first();

    expect($claim->fresh()->status)->toBe(ClaimStatus::Verified)
        ->and($summary->version)->toBe(4)
        ->and($summary->mode)->toBe(SummaryMode::Claimed)
        ->and($summary->creator_id)->toBe($user->creator->id)
        ->and($summary->content['hook'])->toBe('Ships every week.')
        ->and(app(OnboardingProgress::class)->forChannel($channel->fresh())['ready'])->toBeTrue();

    Bus::assertNotDispatched(ProcessChannel::class);
    Bus::assertNotDispatched(FetchChannelInfo::class);
});

it('finds codes posted in the channel too', function () {
    $user = User::factory()->create();
    Creator::factory()->for($user)->create();
    $claim = Claim::create([
        'user_id' => $user->id, 'channel_username' => 'mychannel', 'purpose' => ClaimPurpose::Claim,
        'code' => 'BUILDR-ABC123', 'status' => ClaimStatus::Pending, 'expires_at' => now()->addDay(),
    ]);
    fakeChannelPages('mychannel', 'No code here', 'Verifying my page BUILDR-ABC123');

    app(ClaimService::class)->check($claim);

    expect($claim->fresh()->status)->toBe(ClaimStatus::Verified)
        ->and($claim->fresh()->found_in)->toBe('post')
        ->and($claim->fresh()->found_msg_id)->toBe(100);
});

it('rejects bots, groups, blocklisted and already-claimed channels', function () {
    $user = User::factory()->create();
    Creator::factory()->for($user)->create(['onboarding_step' => 2]);

    Http::fake(['t.me/botfather' => Http::response(fixtureContents('telegram/bot-botfather.html'))]);
    $this->actingAs($user)->post('/onboarding/channel', ['username' => '@botfather'])->assertSessionHasErrors('username');

    BlockedUsername::block('gonechannel');
    $this->post('/onboarding/channel', ['username' => '@gonechannel'])->assertSessionHasErrors('username');

    $owner = Creator::factory()->create();
    Channel::factory()->claimedBy($owner)->create(['username' => 'takenchannel']);
    $this->post('/onboarding/channel', ['username' => '@takenchannel'])->assertSessionHasErrors('username');
    expect(session('errors')->first('username'))->toContain('dispute');

    expect(Claim::count())->toBe(0);
});

it('resolves disputes in favour of whoever posts their code', function () {
    $owner = User::factory()->create();
    $ownerCreator = Creator::factory()->for($owner)->create();
    $channel = Channel::factory()->claimedBy($ownerCreator)->create(['username' => 'contested']);
    $challenger = User::factory()->create();

    [$mine, $theirs] = app(ClaimService::class)->openDispute($challenger, '@contested');

    fakeChannelPages('contested', "now mine {$mine->code}");
    app(ClaimService::class)->check($mine);

    expect($channel->fresh()->creator_id)->toBe($challenger->fresh()->creator->id)
        ->and($theirs->fresh()->status)->toBe(ClaimStatus::Failed);
});

it('removes a channel through the removal flow and blocklists it', function () {
    $user = User::factory()->create();
    Creator::factory()->for($user)->create();
    $channel = Channel::factory()->published()->create(['username' => 'removeme']);
    Post::factory()->for($channel)->count(3)->create();
    fakeChannelPages('removeme');

    $this->actingAs($user)->post('/remove', ['username' => '@removeme'])->assertRedirect();

    $claim = Claim::where('purpose', ClaimPurpose::Removal)->firstOrFail();
    fakeChannelPages('removeme', "bye {$claim->code}");

    $this->postJson("/api/claims/{$claim->id}/check")->assertJson(['verified' => true]);

    expect($channel->fresh()->visibility)->toBe(ChannelVisibility::Removed)
        ->and(Post::where('channel_id', $channel->id)->count())->toBe(0)
        ->and(BlockedUsername::isBlocked('removeme'))->toBeTrue();

    $this->get('/c/removeme')->assertStatus(410);
});

it('expires stale codes', function () {
    $user = User::factory()->create();
    Claim::create([
        'user_id' => $user->id, 'channel_username' => 'x1234', 'purpose' => ClaimPurpose::Claim,
        'code' => 'BUILDR-ZZZZZZ', 'status' => ClaimStatus::Pending, 'expires_at' => now()->subMinute(),
    ]);

    expect(app(ClaimService::class)->expireStale())->toBe(1);
});
