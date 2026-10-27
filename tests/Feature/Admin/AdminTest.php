<?php

use App\Enums\ChannelReviewStatus;
use App\Enums\ChannelVisibility;
use App\Enums\CreatorStatus;
use App\Enums\ReportStatus;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Models\AuditLog;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\FeaturedItem;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function admin(): User
{
    return User::factory()->admin()->withTwoFactor()->create();
}

function submittedCreator(): Creator
{
    $user = User::factory()->create();
    $creator = Creator::factory()->for($user)->pendingReview()->create(['slug' => 'selam', 'display_name' => 'Selam']);
    $channel = Channel::factory()->claimedBy($creator)->create(['username' => 'selambuilds']);
    $creator->update(['primary_channel_id' => $channel->id]);

    Summary::create([
        'channel_id' => $channel->id, 'creator_id' => $creator->id, 'version' => 1, 'mode' => 'claimed',
        'status' => SummaryStatus::Verified, 'prompt_version' => 1, 'triggered_by' => 'system',
        'content' => ['hook' => 'Ships weekly', 'intro' => 'Builds tools'], 'citations' => ['hook' => [1], 'intro' => [1]],
        'verification' => ['passed' => true, 'dropped' => [], 'llm' => []],
    ]);

    return $creator;
}

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
});

it('only lets admins with a password through the admin login', function () {
    $admin = User::factory()->admin()->create(['email' => 'boss@example.com']);
    $creator = User::factory()->create();

    $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'wrong'])->assertSessionHasErrors();
    $this->assertGuest();

    $this->post('/admin/login', ['email' => 'boss@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($admin);

    auth()->logout();
    $this->actingAs($creator)->get('/admin')->assertForbidden();
});

it('forces admins to set up 2FA before anything else', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.security'));
    $this->actingAs($admin)->get('/admin/review')->assertRedirect(route('admin.security'));
});

it('ends admin sessions after two idle hours', function () {
    $admin = admin();

    $this->actingAs($admin)->withSession(['admin_last_activity' => time() - 3 * 3600])->get('/admin')->assertRedirect('/admin/login');
    $this->assertGuest();
});

it('shows the dashboard counters', function () {
    submittedCreator();

    $this->actingAs(admin())->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/index')
        ->where('counters.pending_reviews', 1));
});

it('approves a creator, publishes and indexes them, and logs it', function () {
    $creator = submittedCreator();
    $admin = admin();

    $this->actingAs($admin)->get("/admin/review/{$creator->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/review/creator'));
    $this->post("/admin/review/{$creator->id}/approve", ['feature' => true])->assertRedirect('/admin/review');

    $creator->refresh();

    expect($creator->status)->toBe(CreatorStatus::Published)
        ->and(DirectoryEntry::where('listable_type', 'channel')->where('creator_id', $creator->id)->exists())->toBeTrue()
        ->and(FeaturedItem::where('featurable_id', $creator->id)->exists())->toBeTrue()
        ->and(Summary::first()->status)->toBe(SummaryStatus::Approved)
        ->and(AuditLog::where('action', 'creator.approved')->where('actor_id', $admin->id)->exists())->toBeTrue();

    $this->get('/selam')->assertOk();
});

it('requests changes and rejects with notes', function () {
    $creator = submittedCreator();
    $admin = admin();

    $this->actingAs($admin)->post("/admin/review/{$creator->id}/changes", ['note' => 'Add a bio'])->assertRedirect();
    expect($creator->fresh()->status)->toBe(CreatorStatus::ChangesRequested)
        ->and($creator->fresh()->review_note)->toBe('Add a bio');

    $this->post("/admin/review/{$creator->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/admin/review/{$creator->id}/reject", ['reason' => 'Not a builder'])->assertRedirect();
    expect($creator->fresh()->status)->toBe(CreatorStatus::Rejected);
});

it('applies reviewed name and slug changes', function () {
    $creator = submittedCreator();
    $creator->update(['status' => CreatorStatus::Published, 'pending_changes' => ['slug' => 'selam-t', 'display_name' => 'Selam T']]);

    $this->actingAs(admin())->post("/admin/review/{$creator->id}/fields/approve")->assertRedirect();

    expect($creator->fresh()->slug)->toBe('selam-t')
        ->and($creator->fresh()->display_name)->toBe('Selam T')
        ->and($creator->fresh()->pending_changes)->toBeNull();
});

it('bulk adds channels, skipping invalid, blocked and duplicate handles', function () {
    Channel::factory()->create(['username' => 'existing']);
    BlockedUsername::block('blockedone');

    $this->actingAs(admin())->post('/admin/channels', ['handles' => "@newone\nhttps://t.me/newtwo\nexisting\nblockedone\nno!"])
        ->assertRedirect('/admin/channels');

    expect(Channel::whereIn('username', ['newone', 'newtwo'])->count())->toBe(2);
    Bus::assertDispatched(FetchChannelInfo::class, 2);
});

it('publishes unclaimed channels from the review queue', function () {
    $channel = Channel::factory()->create(['username' => 'bignews', 'review_status' => ChannelReviewStatus::Pending]);

    $this->actingAs(admin())->post("/admin/review/channel/{$channel->id}/publish")->assertRedirect();

    expect($channel->fresh()->visibility)->toBe(ChannelVisibility::Published);
    $this->get('/c/bignews')->assertOk();
});

it('edits sections on unclaimed pages as admin overrides', function () {
    $channel = Channel::factory()->published()->create(['username' => 'bignews']);
    Summary::create([
        'channel_id' => $channel->id, 'version' => 1, 'mode' => 'unclaimed', 'status' => 'verified',
        'prompt_version' => 1, 'triggered_by' => 'system', 'content' => ['hook' => 'Old', 'intro' => 'Intro'],
    ]);

    $this->actingAs(admin())->put("/admin/sections/{$channel->id}/hook", ['value' => 'Fixed hook'])->assertRedirect();

    $override = $channel->overrides()->first();
    expect($override->source->value)->toBe('admin')
        ->and($override->value['v'])->toBe('Fixed hook');
});

it('resolves reports with a required note', function () {
    $channel = Channel::factory()->published()->create(['username' => 'spammy']);
    $report = Report::create(['target_type' => 'channel', 'target_id' => $channel->id, 'reason' => 'spam']);

    $this->actingAs(admin())->post("/admin/reports/{$report->id}/resolve", ['action' => 'remove', 'note' => ''])->assertSessionHasErrors('note');
    $this->post("/admin/reports/{$report->id}/resolve", ['action' => 'remove', 'note' => 'Pure spam'])->assertRedirect();

    expect($report->fresh()->status)->toBe(ReportStatus::Resolved)
        ->and($channel->fresh()->visibility)->toBe(ChannelVisibility::Removed)
        ->and(BlockedUsername::isBlocked('spammy'))->toBeTrue();
});

it('bans users and hides their profile', function () {
    $creator = submittedCreator();
    $creator->update(['status' => CreatorStatus::Published, 'published_at' => now()]);

    $this->actingAs(admin())->post("/admin/creators/{$creator->id}/ban", ['reason' => 'Impersonation'])->assertRedirect();

    expect($creator->user->fresh()->isBanned())->toBeTrue();
    auth()->logout();
    $this->get('/selam')->assertNotFound();
});

it('toggles the scrape kill switch and AI pause, and edits the budget', function () {
    $this->actingAs(admin())->post('/admin/jobs/scraping/toggle')->assertRedirect();
    expect(Setting::flag(Setting::SCRAPING_PAUSED))->toBeTrue();

    $this->post('/admin/ai-costs/toggle')->assertRedirect();
    expect(Setting::flag(Setting::AI_PAUSED))->toBeTrue();

    $this->put('/admin/ai-costs/budget', ['budget' => 25])->assertRedirect();
    expect(Setting::read(Setting::AI_DAILY_BUDGET_USD))->toEqual(25);
});

it('renders every admin page', function (string $url) {
    submittedCreator();

    $this->actingAs(admin())->get($url)->assertOk();
})->with(['/admin/review', '/admin/channels', '/admin/channels/new', '/admin/creators', '/admin/claims', '/admin/reports', '/admin/jobs', '/admin/ai-costs', '/admin/featured', '/admin/tags', '/admin/blocklist', '/admin/audit']);

it('acts on many queue items at once and skips ones that already changed', function () {
    $admin = admin();
    $first = submittedCreator();
    $user = User::factory()->create();
    $second = Creator::factory()->for($user)->pendingReview()->create(['slug' => 'dawit', 'display_name' => 'Dawit']);
    $done = Creator::factory()->for(User::factory()->create())->published()->create(['slug' => 'done']);

    $this->actingAs($admin)->get('/admin/review')->assertInertia(fn (Assert $page) => $page
        ->where('creators.0.profile', 'ready')
        ->where('creators.1.profile', 'none'));

    $this->actingAs($admin)->post('/admin/review/bulk', ['action' => 'approve_creators', 'ids' => [$first->id, $second->id, $done->id]])
        ->assertRedirect('/admin/review')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Approved and published 2. Skipped 1 that had already changed.');

    expect($first->fresh()->status)->toBe(CreatorStatus::Published)
        ->and($second->fresh()->status)->toBe(CreatorStatus::Published);

    // Rewriting queues one pipeline run per channel.
    $channel = Channel::factory()->create(['username' => 'needsawrite']);
    $this->actingAs($admin)->post('/admin/review/bulk', ['action' => 'regenerate', 'ids' => [$channel->id]])->assertRedirect();
    Bus::assertDispatched(ProcessChannel::class, fn ($job) => $job->channelId === $channel->id && $job->step === 'summary_start');

    // Approving failed drafts anyway.
    $failed = Summary::create([
        'channel_id' => $channel->id, 'version' => 1, 'mode' => 'unclaimed', 'status' => SummaryStatus::FailedVerification,
        'prompt_version' => 1, 'triggered_by' => 'system', 'content' => ['intro' => 'Builds tools'], 'citations' => ['intro' => [1]],
        'verification' => ['passed' => false, 'dropped' => [['section' => 'hook', 'item' => 'x', 'reason' => 'unsupported']], 'llm' => []],
    ]);
    $this->actingAs($admin)->get('/admin/review')->assertInertia(fn (Assert $page) => $page
        ->where('failed.0.missing', ['hook'])
        ->where('failed.0.dropped', 1)
        ->where('failed.0.has_live', false));
    $this->actingAs($admin)->post('/admin/review/bulk', ['action' => 'approve_summaries', 'ids' => [$failed->id]])->assertRedirect();
    expect($failed->fresh()->status)->toBe(SummaryStatus::Approved);

    // Creators can't.
    $this->actingAs(User::factory()->create())->post('/admin/review/bulk', ['action' => 'regenerate', 'ids' => [$channel->id]])->assertForbidden();
});
