<?php

use App\Enums\ChannelClaimStatus;
use App\Enums\SponsorshipStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Bus::fake();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Returns a published creator with a claimed channel, already logged in.
 *
 * @return array{user: User, creator: Creator, channel: Channel}
 */
function sponsoredChannelSetup(): array
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->published()->create();
    $channel = Channel::factory()->claimedBy($creator)->create();

    return compact('user', 'creator', 'channel');
}

// ---------------------------------------------------------------------------
// GET /dashboard/channels — sponsorship data in payload
// ---------------------------------------------------------------------------

it('includes null sponsorship for a channel with no request', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/channels')
            ->where('channels.0.sponsorship', null),
        );
});

it('includes pending sponsorship status in the channel payload', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->pending()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.sponsorship.status', 'pending'),
        );
});

it('includes approved sponsorship with starts_at in the channel payload', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->active()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.sponsorship.status', 'approved')
            ->whereNot('channels.0.sponsorship.starts_at', null),
        );
});

it('surfaces expired as effective status when ends_at has passed', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->expired()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.sponsorship.status', 'expired'),
        );
});

it('omits cancelled sponsorships from the channel payload', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->cancelled()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.sponsorship', null),
        );
});

it('includes rejected sponsorship with review_note in the channel payload', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->rejected('Not a fit right now.')->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.sponsorship.status', 'rejected')
            ->where('channels.0.sponsorship.review_note', 'Not a fit right now.'),
        );
});

// ---------------------------------------------------------------------------
// POST /dashboard/channels/{channel}/sponsorship
// ---------------------------------------------------------------------------

it('creates a pending sponsorship for an owned claimed channel', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();

    $this->actingAs($user)
        ->post("/dashboard/channels/{$channel->id}/sponsorship")
        ->assertRedirect();

    expect(Sponsorship::where('channel_id', $channel->id)->where('status', SponsorshipStatus::Pending)->exists())->toBeTrue();
});

it('returns a validation error when the channel does not belong to the creator', function () {
    ['user' => $user] = sponsoredChannelSetup();
    $other = Channel::factory()->claimedBy(Creator::factory()->published()->create())->create();

    $this->actingAs($user)
        ->post("/dashboard/channels/{$other->id}/sponsorship")
        ->assertSessionHasErrors('channel');

    expect(Sponsorship::count())->toBe(0);
});

it('returns a validation error when the channel is not claimed', function () {
    ['user' => $user] = sponsoredChannelSetup();
    $unclaimed = Channel::factory()->create(['claim_status' => ChannelClaimStatus::Unclaimed]);

    $this->actingAs($user)
        ->post("/dashboard/channels/{$unclaimed->id}/sponsorship")
        ->assertSessionHasErrors('channel');
});

it('returns a validation error when a pending request already exists', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->pending()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->post("/dashboard/channels/{$channel->id}/sponsorship")
        ->assertSessionHasErrors('channel');

    expect(Sponsorship::count())->toBe(1);
});

it('returns a validation error when an active sponsorship already exists', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->active()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->post("/dashboard/channels/{$channel->id}/sponsorship")
        ->assertSessionHasErrors('channel');
});

it('redirects guests away from the sponsorship store route', function () {
    $channel = Channel::factory()->create();

    $this->post("/dashboard/channels/{$channel->id}/sponsorship")
        ->assertRedirect('/login');
});

it('returns 403 when an admin tries to use the creator sponsorship route', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $channel = Channel::factory()->create();

    $this->actingAs($admin)
        ->post("/dashboard/channels/{$channel->id}/sponsorship")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DELETE /dashboard/sponsorships/{sponsorship}
// ---------------------------------------------------------------------------

it('allows a creator to cancel their own pending request', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->delete("/dashboard/sponsorships/{$s->id}")
        ->assertRedirect();

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Cancelled);
});

it("returns a validation error when trying to cancel another creator's request", function () {
    ['channel' => $channel] = sponsoredChannelSetup();
    ['user' => $otherUser] = sponsoredChannelSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();

    $this->actingAs($otherUser)
        ->delete("/dashboard/sponsorships/{$s->id}")
        ->assertSessionHasErrors('sponsorship');

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Pending);
});

it('returns a validation error when trying to cancel an approved sponsorship', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    $s = Sponsorship::factory()->for($channel)->active()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->delete("/dashboard/sponsorships/{$s->id}")
        ->assertSessionHasErrors('sponsorship');

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Approved);
});

it('returns 404 for a non-existent sponsorship', function () {
    ['user' => $user] = sponsoredChannelSetup();

    $this->actingAs($user)
        ->delete('/dashboard/sponsorships/99999')
        ->assertNotFound();
});

it('redirects guests away from the cancel route', function () {
    $s = Sponsorship::factory()->pending()->create();

    $this->delete("/dashboard/sponsorships/{$s->id}")
        ->assertRedirect('/login');
});

it('returns 403 when an admin tries to use the creator cancel route', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $s = Sponsorship::factory()->pending()->create();

    $this->actingAs($admin)
        ->delete("/dashboard/sponsorships/{$s->id}")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Dashboard channels page renders without errors with sponsorship data
// ---------------------------------------------------------------------------

it('renders the channels page when a channel has an active sponsorship', function () {
    ['user' => $user, 'channel' => $channel] = sponsoredChannelSetup();
    Sponsorship::factory()->for($channel)->active()->create(['requested_by' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dashboard/channels'));
});
