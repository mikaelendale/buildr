<?php

use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Models\Channel;
use App\Models\Post;
use App\Models\Summary;
use App\Models\User;
use App\Services\Admin\PipelineStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function summaryFor(Channel $channel, SummaryStatus $status): Summary
{
    return Summary::create([
        'channel_id' => $channel->id, 'version' => 1, 'mode' => SummaryMode::Unclaimed, 'status' => $status,
        'prompt_version' => 1, 'triggered_by' => 'system', 'content' => ['hook' => 'x'],
    ]);
}

it('reads each channel\'s stage from its state and its queued jobs', function () {
    config(['queue.default' => 'database']);
    $status = app(PipelineStatus::class);

    $new = Channel::factory()->create(['backfill_complete' => false]);
    $review = Channel::factory()->create(['backfill_complete' => true]);
    summaryFor($review, SummaryStatus::Verified);
    $failed = Channel::factory()->create(['backfill_complete' => true]);
    summaryFor($failed, SummaryStatus::FailedVerification);
    $live = Channel::factory()->published()->create(['backfill_complete' => true]);
    summaryFor($live, SummaryStatus::Verified);
    $paused = Channel::factory()->create(['backfill_complete' => true]);
    Cache::forever(ProcessChannel::PAUSED_KEY, [$paused->id => ['step' => 'verify', 'until' => now()->addHour()->toIso8601String()]]);

    // A labelling job that a worker has picked up.
    $labelling = Channel::factory()->create(['backfill_complete' => true]);
    Post::factory()->for($labelling)->count(3)->create();
    DB::table('jobs')->insert([
        'queue' => 'ai',
        'payload' => json_encode(['displayName' => ProcessChannel::class, 'data' => ['command' => serialize(new ProcessChannel($labelling->id, 'extract'))]]),
        'attempts' => 1, 'reserved_at' => now()->getTimestamp(), 'available_at' => now()->getTimestamp(), 'created_at' => now()->getTimestamp(),
    ]);

    $stages = collect($status->forChannels(Channel::all()))->map(fn (array $p) => $p['stage']);

    expect($stages[$new->id])->toBe('Queued')
        ->and($stages[$review->id])->toBe('Needs review')
        ->and($stages[$failed->id])->toBe('Failed fact-check')
        ->and($stages[$live->id])->toBe('Live')
        ->and($stages[$paused->id])->toBe('Waiting for AI quota')
        ->and($stages[$labelling->id])->toBe('Labelling posts')
        ->and($status->forChannel($labelling))->toMatchArray(['active' => true, 'queued' => 1, 'progress' => ['done' => 0, 'total' => 3]]);
});

it('shows pipeline stages on the admin channel list and live workers on the jobs page', function () {
    Channel::factory()->create(['username' => 'freshone', 'backfill_complete' => false]);

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get('/admin/channels')->assertInertia(fn (Assert $page) => $page
        ->where('channels.data.0.pipeline.stage', 'Queued'));

    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())->get('/admin/jobs')->assertInertia(fn (Assert $page) => $page
        ->has('running')
        ->has('waiting')
        ->has('recentAi')
        ->has('lanes', 1));
});
