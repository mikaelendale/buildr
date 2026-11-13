<?php

use App\Enums\SponsorshipStatus;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Sponsorship;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->withTwoFactor()->create();
    $this->creator = Creator::factory()->for(User::factory())->create();
    $this->channel = Channel::factory()->claimedBy($this->creator)->create();
});

it('displays the admin sponsorships page with pending and active sponsorships', function () {
    Sponsorship::factory()->pending()->for($this->channel)->create();
    Sponsorship::factory()->active()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)->get('/admin/featured/sponsorships');

    expect($response->status())->toBe(200);
    $props = $response->props();
    expect($props['pending'])->toHaveCount(1);
    expect($props['active'])->toHaveCount(1);
});

it('requires admin role to view sponsorships page', function () {
    $user = User::factory()->creator()->create();
    $response = $this->actingAs($user)->get('/admin/featured/sponsorships');

    expect($response->status())->toBe(403);
});

it('approves a pending sponsorship request', function () {
    $sponsorship = Sponsorship::factory()->pending()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)
        ->post("/admin/sponsorships/{$sponsorship->id}/approve");

    expect($response->status())->toBe(302);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Approved);
    expect($sponsorship->fresh()->starts_at)->not->toBeNull();
});

it('rejects a pending sponsorship request with a reason', function () {
    $sponsorship = Sponsorship::factory()->pending()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)
        ->post("/admin/sponsorships/{$sponsorship->id}/reject", [
            'reason' => 'Content not aligned with our values.',
        ]);

    expect($response->status())->toBe(302);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Rejected);
    expect($sponsorship->fresh()->review_note)->toBe('Content not aligned with our values.');
});

it('requires a non-empty reason to reject', function () {
    $sponsorship = Sponsorship::factory()->pending()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)
        ->post("/admin/sponsorships/{$sponsorship->id}/reject", [
            'reason' => '',
        ]);

    expect($response->status())->toBe(422);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Pending);
});

it('ends an active sponsorship', function () {
    $sponsorship = Sponsorship::factory()->active()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)
        ->post("/admin/sponsorships/{$sponsorship->id}/end");

    expect($response->status())->toBe(302);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Cancelled);
});

it('ends an active sponsorship with an optional reason', function () {
    $sponsorship = Sponsorship::factory()->active()->for($this->channel)->create();

    $response = $this->actingAs($this->admin)
        ->post("/admin/sponsorships/{$sponsorship->id}/end", [
            'reason' => 'Campaign period ended.',
        ]);

    expect($response->status())->toBe(302);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Cancelled);
    expect($sponsorship->fresh()->review_note)->toBe('Campaign period ended.');
});

it('blocks non-admins from approving sponsorships', function () {
    $user = User::factory()->creator()->create();
    $sponsorship = Sponsorship::factory()->pending()->for($this->channel)->create();

    $response = $this->actingAs($user)
        ->post("/admin/sponsorships/{$sponsorship->id}/approve");

    expect($response->status())->toBe(403);
    expect($sponsorship->fresh()->status)->toBe(SponsorshipStatus::Pending);
});
