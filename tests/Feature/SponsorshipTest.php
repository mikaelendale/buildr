<?php

use App\Enums\SponsorshipStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Sponsorship;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->creator = Creator::factory()->for(User::factory())->create();
    $this->channel = Channel::factory()->claimedBy($this->creator)->create();
});

it('creates the sponsorships table with the expected columns and indexes', function () {
    expect(Schema::hasTable('sponsorships'))->toBeTrue()
        ->and(Schema::hasColumns('sponsorships', [
            'id', 'channel_id', 'requested_by', 'status', 'review_note',
            'reviewed_at', 'starts_at', 'ends_at', 'created_at', 'updated_at',
        ]))->toBeTrue();

    $indexed = collect(Schema::getIndexes('sponsorships'))
        ->flatMap(fn ($i) => $i['columns'])
        ->unique()
        ->values();

    expect($indexed)->toContain('ends_at')->toContain('status');
});

it('defaults a new sponsorship to pending and casts status to the enum', function () {
    $sponsorship = Sponsorship::factory()->for($this->channel)->create();

    expect($sponsorship->status)->toBe(SponsorshipStatus::Pending)
        ->and($sponsorship->status)->toBeInstanceOf(SponsorshipStatus::class);
});

it('reads the per-creator active cap from config', function () {
    expect(config('buildr.sponsorships.max_active_per_creator'))->toBe(1);
});

it('scopes pending requests', function () {
    Sponsorship::factory()->pending()->create();
    Sponsorship::factory()->active()->create();
    Sponsorship::factory()->rejected()->create();
    Sponsorship::factory()->cancelled()->create();

    expect(Sponsorship::pending()->count())->toBe(1);
});

it('scopes active sponsorships to the current window', function () {
    Sponsorship::factory()->active()->create();                              // active: started, no end
    Sponsorship::factory()->active()->create(['ends_at' => now()->addDay()]); // active: ends tomorrow
    Sponsorship::factory()->expired()->create();                            // approved but ended
    Sponsorship::factory()->active()->create(['starts_at' => now()->addDay()]); // approved but not started
    Sponsorship::factory()->pending()->create();                           // not approved

    expect(Sponsorship::active()->count())->toBe(2);
});

it('derives expired status for approved sponsorships past their ends_at', function () {
    expect(Sponsorship::factory()->active()->create()->effectiveStatus())->toBe('approved')
        ->and(Sponsorship::factory()->active()->create(['ends_at' => null])->effectiveStatus())->toBe('approved')
        ->and(Sponsorship::factory()->expired()->create()->effectiveStatus())->toBe('expired')
        ->and(Sponsorship::factory()->pending()->create()->effectiveStatus())->toBe('pending')
        ->and(Sponsorship::factory()->rejected()->create()->effectiveStatus())->toBe('rejected')
        ->and(Sponsorship::factory()->cancelled()->create()->effectiveStatus())->toBe('cancelled');
});

it('relates a channel to its sponsorships', function () {
    expect($this->channel->sponsorships)->toBeEmpty();

    Sponsorship::factory()->for($this->channel)->pending()->create();

    expect($this->channel->fresh()->sponsorships)->toHaveCount(1)
        ->and($this->channel->fresh()->sponsorships->first()->channel_id)->toBe($this->channel->id);
});

it('relates a sponsorship to its channel and requesting user', function () {
    $user = User::factory()->create();
    $sponsorship = Sponsorship::factory()->for($this->channel)->create(['requested_by' => $user->id]);

    expect($sponsorship->channel->is($this->channel))->toBeTrue()
        ->and($sponsorship->requestedBy->is($user))->toBeTrue();
});

it('blocks a second pending sponsorship for the same channel', function () {
    Sponsorship::factory()->for($this->channel)->pending()->create();

    expect(fn () => Sponsorship::factory()->for($this->channel)->pending()->create())
        ->toThrow(QueryException::class);
});

it('blocks an approved sponsorship while a pending one exists for the channel', function () {
    Sponsorship::factory()->for($this->channel)->pending()->create();

    expect(fn () => Sponsorship::factory()->for($this->channel)->active()->create())
        ->toThrow(QueryException::class);
});

it('allows a new request after the previous one was rejected', function () {
    Sponsorship::factory()->for($this->channel)->rejected()->create();

    $threw = false;
    try {
        Sponsorship::factory()->for($this->channel)->pending()->create();
    } catch (QueryException) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

it('allows a new request after the previous one was cancelled', function () {
    Sponsorship::factory()->for($this->channel)->cancelled()->create();

    $threw = false;
    try {
        Sponsorship::factory()->for($this->channel)->pending()->create();
    } catch (QueryException) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});
