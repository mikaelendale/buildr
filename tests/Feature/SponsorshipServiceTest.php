<?php

use App\Enums\ChannelClaimStatus;
use App\Enums\SponsorshipStatus;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Sponsorships\SponsorshipException;
use App\Services\Sponsorships\SponsorshipService;
use Illuminate\Support\Facades\Bus;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function sponsorshipService(): SponsorshipService
{
    return app(SponsorshipService::class);
}

/**
 * Returns a published creator whose claimed channel is ready to sponsor.
 *
 * @return array{user: User, creator: Creator, channel: Channel}
 */
function claimedSetup(): array
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->published()->create();
    $channel = Channel::factory()->claimedBy($creator)->create();

    return compact('user', 'creator', 'channel');
}

function sponsorAdmin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

beforeEach(function () {
    // SendTelegramMessage dispatches real jobs; keep them in the fake bus.
    Bus::fake();
});

// ---------------------------------------------------------------------------
// request()
// ---------------------------------------------------------------------------

it('creates a pending sponsorship for an owned claimed channel', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();

    $s = sponsorshipService()->request($user, $channel);

    expect($s)->toBeInstanceOf(Sponsorship::class)
        ->and($s->status)->toBe(SponsorshipStatus::Pending)
        ->and($s->channel_id)->toBe($channel->id)
        ->and($s->requested_by)->toBe($user->id)
        ->and($s->starts_at)->toBeNull()
        ->and($s->ends_at)->toBeNull();
});

it('rejects a request when the user has no creator profile', function () {
    $user = User::factory()->create();
    $channel = Channel::factory()->create(['claim_status' => ChannelClaimStatus::Unclaimed]);

    $e = null;
    try {
        sponsorshipService()->request($user, $channel);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('not_owner');
});

it('rejects a request for an unclaimed channel', function () {
    $user = User::factory()->create();
    Creator::factory()->for($user)->published()->create();
    $channel = Channel::factory()->create(['claim_status' => ChannelClaimStatus::Unclaimed]);

    $e = null;
    try {
        sponsorshipService()->request($user, $channel);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('not_owner');
});

it('rejects a request for a channel owned by a different creator', function () {
    ['user' => $user] = claimedSetup();
    $other = Channel::factory()->claimedBy(Creator::factory()->published()->create())->create();

    $e = null;
    try {
        sponsorshipService()->request($user, $other);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('not_owner');
});

it('rejects a duplicate request while one is already pending', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();

    sponsorshipService()->request($user, $channel);

    $e = null;
    try {
        sponsorshipService()->request($user, $channel);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('duplicate');
    expect(Sponsorship::count())->toBe(1);
});

it('rejects a new request while an approved one is active', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();
    Sponsorship::factory()->for($channel)->active()->create();

    $e = null;
    try {
        sponsorshipService()->request($user, $channel);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('duplicate');
});

it('allows a new request after the previous one was rejected', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();
    Sponsorship::factory()->for($channel)->rejected()->create();

    $s = sponsorshipService()->request($user, $channel);

    expect($s->status)->toBe(SponsorshipStatus::Pending);
});

it('allows a new request after the previous one was cancelled', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();
    Sponsorship::factory()->for($channel)->cancelled()->create();

    $s = sponsorshipService()->request($user, $channel);

    expect($s->status)->toBe(SponsorshipStatus::Pending);
});

// ---------------------------------------------------------------------------
// cancel()
// ---------------------------------------------------------------------------

it('lets the requesting creator cancel their own pending request', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();
    $s = sponsorshipService()->request($user, $channel);

    sponsorshipService()->cancel($user, $s);

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Cancelled);
});

it("blocks a creator from cancelling another creator's request", function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();
    $other = User::factory()->create();

    $e = null;
    try {
        sponsorshipService()->cancel($other, $s);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('not_owner');
    expect($s->fresh()->status)->toBe(SponsorshipStatus::Pending);
});

it('blocks cancelling an already-approved sponsorship', function () {
    ['user' => $user, 'channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->active()->create(['requested_by' => $user->id]);

    $e = null;
    try {
        sponsorshipService()->cancel($user, $s);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

// ---------------------------------------------------------------------------
// approve()
// ---------------------------------------------------------------------------

it('approves a pending request and sets starts_at to now', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();

    sponsorshipService()->approve($s, sponsorAdmin());

    $s->refresh();
    expect($s->status)->toBe(SponsorshipStatus::Approved)
        ->and($s->starts_at)->not->toBeNull()
        ->and($s->ends_at)->toBeNull()
        ->and($s->reviewed_at)->not->toBeNull();
});

it('writes a sponsorship.approved audit log on approval', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();
    $admin = sponsorAdmin();

    sponsorshipService()->approve($s, $admin);

    expect(
        AuditLog::where('action', 'sponsorship.approved')
            ->where('actor_id', $admin->id)
            ->exists()
    )->toBeTrue();
});

it('notifies the requesting creator after approval', function () {
    $notifier = Mockery::mock(TelegramNotifier::class);
    $notifier->shouldReceive('toUser')->once();
    app()->instance(TelegramNotifier::class, $notifier);

    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();

    app(SponsorshipService::class)->approve($s, sponsorAdmin());
});

it('blocks approving a non-pending request', function () {
    $s = Sponsorship::factory()->active()->create();

    $e = null;
    try {
        sponsorshipService()->approve($s, sponsorAdmin());
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

it('blocks a duplicate approval via the atomic update', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();
    $admin = sponsorAdmin();

    sponsorshipService()->approve($s, $admin);
    $s->refresh();

    // Row is now approved; a second call must fail cleanly.
    $e = null;
    try {
        sponsorshipService()->approve($s, $admin);
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

// ---------------------------------------------------------------------------
// reject()
// ---------------------------------------------------------------------------

it('rejects a pending request and stores the reason', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();

    sponsorshipService()->reject($s, sponsorAdmin(), 'Not a fit right now.');

    $s->refresh();
    expect($s->status)->toBe(SponsorshipStatus::Rejected)
        ->and($s->review_note)->toBe('Not a fit right now.')
        ->and($s->reviewed_at)->not->toBeNull();
});

it('writes a sponsorship.rejected audit log with the reason', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();
    $admin = sponsorAdmin();

    sponsorshipService()->reject($s, $admin, 'Content does not fit.');

    expect(
        AuditLog::where('action', 'sponsorship.rejected')
            ->where('actor_id', $admin->id)
            ->whereJsonContains('meta->reason', 'Content does not fit.')
            ->exists()
    )->toBeTrue();
});

it('notifies the requesting creator after rejection', function () {
    $notifier = Mockery::mock(TelegramNotifier::class);
    $notifier->shouldReceive('toUser')->once();
    app()->instance(TelegramNotifier::class, $notifier);

    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->pending()->create();

    app(SponsorshipService::class)->reject($s, sponsorAdmin(), 'Not a fit right now.');
});

it('requires a non-blank reason to reject', function () {
    $s = Sponsorship::factory()->pending()->create();

    foreach (['', '   '] as $blank) {
        $e = null;
        try {
            sponsorshipService()->reject($s, sponsorAdmin(), $blank);
        } catch (SponsorshipException $ex) {
            $e = $ex;
        }
        expect($e)->not->toBeNull()
            ->and($e->reason)->toBe('reason_required');
    }

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Pending);
});

it('blocks rejecting a non-pending request', function () {
    $s = Sponsorship::factory()->active()->create();

    $e = null;
    try {
        sponsorshipService()->reject($s, sponsorAdmin(), 'Too late.');
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

// ---------------------------------------------------------------------------
// end()
// ---------------------------------------------------------------------------

it('ends an active sponsorship', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->active()->create();

    sponsorshipService()->end($s, sponsorAdmin());

    expect($s->fresh()->status)->toBe(SponsorshipStatus::Cancelled)
        ->and($s->fresh()->reviewed_at)->not->toBeNull();
});

it('persists an optional reason when ending', function () {
    $s = Sponsorship::factory()->active()->create();

    sponsorshipService()->end($s, sponsorAdmin(), 'Campaign finished.');

    expect($s->fresh()->review_note)->toBe('Campaign finished.');
});

it('writes a sponsorship.ended audit log', function () {
    ['channel' => $channel] = claimedSetup();
    $s = Sponsorship::factory()->for($channel)->active()->create();
    $admin = sponsorAdmin();

    sponsorshipService()->end($s, $admin);

    expect(
        AuditLog::where('action', 'sponsorship.ended')
            ->where('actor_id', $admin->id)
            ->exists()
    )->toBeTrue();
});

it('notifies the requesting creator after ending', function () {
    $notifier = Mockery::mock(TelegramNotifier::class);
    $notifier->shouldReceive('toUser')->once();
    app()->instance(TelegramNotifier::class, $notifier);

    $s = Sponsorship::factory()->active()->create();

    app(SponsorshipService::class)->end($s, sponsorAdmin());
});

it('blocks ending a pending request', function () {
    $s = Sponsorship::factory()->pending()->create();

    $e = null;
    try {
        sponsorshipService()->end($s, sponsorAdmin());
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

it('blocks ending an already-cancelled sponsorship', function () {
    $s = Sponsorship::factory()->cancelled()->create();

    $e = null;
    try {
        sponsorshipService()->end($s, sponsorAdmin());
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

it('blocks ending an expired sponsorship', function () {
    $s = Sponsorship::factory()->expired()->create();

    $e = null;
    try {
        sponsorshipService()->end($s, sponsorAdmin());
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('wrong_status');
});

// ---------------------------------------------------------------------------
// max_active_per_creator cap
// ---------------------------------------------------------------------------

it('enforces the per-creator active cap on approval', function () {
    // Creator already has one active sponsorship on their primary channel.
    ['creator' => $creator, 'channel' => $ch1] = claimedSetup();
    Sponsorship::factory()->for($ch1)->active()->create();

    // A second channel owned by the same creator.
    $ch2 = Channel::factory()->claimedBy($creator)->create();
    $s2 = Sponsorship::factory()->for($ch2)->pending()->create();

    $e = null;
    try {
        sponsorshipService()->approve($s2, sponsorAdmin());
    } catch (SponsorshipException $ex) {
        $e = $ex;
    }

    expect($e)->not->toBeNull()
        ->and($e->reason)->toBe('cap_reached');
    expect($s2->fresh()->status)->toBe(SponsorshipStatus::Pending);
});

it('allows approval when the creator has only expired promotions', function () {
    ['creator' => $creator, 'channel' => $ch1] = claimedSetup();
    // Expired: approved but ends_at is in the past; must not count toward the cap.
    Sponsorship::factory()->for($ch1)->expired()->create();

    $ch2 = Channel::factory()->claimedBy($creator)->create();
    $s2 = Sponsorship::factory()->for($ch2)->pending()->create();

    sponsorshipService()->approve($s2, sponsorAdmin());

    expect($s2->fresh()->status)->toBe(SponsorshipStatus::Approved);
});

it('counts only currently-owned channels when checking the active cap', function () {
    // A sponsorship on an orphaned (now-unclaimed) channel must not count.
    $ch = Channel::factory()->create(['claim_status' => ChannelClaimStatus::Unclaimed]);
    Sponsorship::factory()->for($ch)->active()->create();

    // A different, unrelated creator requesting.
    ['channel' => $ch2] = claimedSetup();
    $s2 = Sponsorship::factory()->for($ch2)->pending()->create();

    sponsorshipService()->approve($s2, sponsorAdmin());

    expect($s2->fresh()->status)->toBe(SponsorshipStatus::Approved);
});
