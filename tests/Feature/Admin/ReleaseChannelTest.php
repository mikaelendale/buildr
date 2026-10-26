<?php

use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('releases a channel claimed by the wrong account and rewrites it as unclaimed', function () {
    Bus::fake();
    Storage::fake('public');

    $owner = User::factory()->create();
    $creator = Creator::factory()->for($owner)->published()->create(['slug' => 'mike']);
    $own = Channel::factory()->claimedBy($creator)->create(['username' => 'mike_builds']);
    $other = Channel::factory()->claimedBy($creator)->create(['username' => 'someone_else', 'slug' => 'someone']);
    $creator->update(['primary_channel_id' => $other->id]);
    Claim::create([
        'user_id' => $owner->id, 'channel_id' => $other->id, 'channel_username' => 'someone_else', 'purpose' => ClaimPurpose::Claim,
        'code' => 'BUILDR-X', 'status' => ClaimStatus::Verified, 'verified_at' => now(), 'expires_at' => now()->addDay(),
    ]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post("/admin/channels/{$other->id}/release", ['reason' => 'Not theirs'])
        ->assertRedirect();

    expect($other->fresh())
        ->claim_status->value->toBe('unclaimed')
        ->creator_id->toBeNull()
        ->slug->toBeNull()
        ->and($creator->fresh()->primary_channel_id)->toBe($own->id)
        ->and(Claim::where('channel_id', $other->id)->value('status'))->toBe(ClaimStatus::Expired);

    Bus::assertDispatched(ProcessChannel::class, fn (ProcessChannel $job) => $job->channelId === $other->id && $job->step === 'summary_start');
});
