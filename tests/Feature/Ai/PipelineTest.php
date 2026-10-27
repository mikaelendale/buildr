<?php

use App\Ai\Agents\PostExtractor;
use App\Ai\Agents\ProfileWriter;
use App\Enums\ChannelReviewStatus;
use App\Enums\ProjectStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Ai\RegenerateSection;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\ChannelStat;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Summary;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ContentSelector;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\PostExtractionService;
use App\Services\Ai\ProjectBuilder;
use App\Services\Ai\StatsCalculator;
use App\Services\Ai\SummaryGenerator;
use App\Services\Ai\SummaryVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesAi;

uses(FakesAi::class);

function channelWithPosts(int $count = 25, array $attributes = []): Channel
{
    $channel = Channel::factory()->create($attributes + ['username' => 'testbuilder', 'timezone' => 'UTC']);

    foreach (range(1, $count) as $i) {
        Post::factory()->for($channel)->create([
            'msg_id' => $i,
            'posted_at' => now()->subWeeks($count - $i)->setTime(10, 0),
            'text' => $i === 1
                ? 'Launched Chapa Bot v1 today, payments for Telegram shops are live'
                : "Week {$i} update on Chapa Bot: shipped refunds, fixed the webhook retries and wrote docs",
            'views' => 100 * $i,
        ]);
    }

    return $channel;
}

beforeEach(function () {
    Storage::fake('public');
    $this->fakeAi();
});

it('runs the whole pipeline for an unclaimed channel', function () {
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);

    $channel->refresh();
    $summary = Summary::where('channel_id', $channel->id)->latest('version')->first();

    expect(PostExtraction::count())->toBe(25)
        ->and(Project::where('channel_id', $channel->id)->count())->toBe(1)
        ->and(Project::first()->status)->toBe(ProjectStatus::Launched)
        ->and(Project::first()->description)->toContain('payments bot')
        ->and(ChannelStat::where('channel_id', $channel->id)->value('stats')['total_posts'])->toBe(25)
        ->and($summary->status)->toBe(SummaryStatus::Verified)
        ->and($summary->mode)->toBe(SummaryMode::Unclaimed)
        ->and($summary->content['hook'])->toBe('Ships Chapa Bot updates every single week.')
        ->and($summary->content['how_they_work'])->toBeNull()
        ->and($summary->content['in_their_words'])->toHaveCount(1)
        ->and($summary->content['fun_facts'])->toHaveCount(1)
        ->and($summary->month_digests)->not->toBeEmpty()
        ->and($channel->review_status)->toBe(ChannelReviewStatus::Pending)
        ->and($channel->last_processed_at)->not->toBeNull()
        ->and(AiRun::count())->toBeGreaterThan(3)
        ->and(AiRun::where('status', 'ok')->count())->toBe(AiRun::count());
});

it('does not re-extract posts that already have a current extraction', function () {
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);
    $calls = AiRun::where('job', 'extract_posts')->count();

    ProcessChannel::dispatchSync($channel->id);

    expect(AiRun::where('job', 'extract_posts')->count())->toBe($calls);
});

it('skips the AI summary for channels with too few text posts', function () {
    $channel = channelWithPosts(5);

    ProcessChannel::dispatchSync($channel->id);

    expect(Summary::count())->toBe(0)
        ->and(ChannelStat::where('channel_id', $channel->id)->exists())->toBeTrue();
});

it('makes no AI calls when the daily budget is spent', function () {
    $channel = channelWithPosts();
    Setting::write(Setting::AI_DAILY_BUDGET_USD, 1);
    AiRun::create(['job' => 'x', 'model' => 'm', 'cost_usd_est' => 2, 'status' => 'ok']);

    ProcessChannel::dispatchSync($channel->id);

    expect(AiRun::count())->toBe(1)
        ->and(PostExtraction::count())->toBe(0);
});

it('drops claims the checker marks unsupported or sensitive', function () {
    $this->fakeAi(['eras.0' => 'unsupported', 'start_here.0' => 'sensitive']);
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);

    $summary = Summary::where('channel_id', $channel->id)->latest('version')->first();

    expect($summary->status)->toBe(SummaryStatus::Verified)
        ->and($summary->content['eras'])->toBe([])
        ->and($summary->content['start_here'])->toBe([])
        ->and(collect($summary->verification['dropped'])->pluck('section')->all())->toContain('eras', 'start_here');
});

it('writes up to three drafts, then publishes the last one without the hook it could not back', function () {
    $this->fakeAi(['hook' => 'unsupported']);
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);

    $summaries = Summary::where('channel_id', $channel->id)->orderBy('version')->get();
    $last = $summaries->last();

    expect($summaries)->toHaveCount(ProcessChannel::MAX_DRAFTS)
        ->and($summaries->pluck('status')->all())->toBe([SummaryStatus::FailedVerification, SummaryStatus::FailedVerification, SummaryStatus::Verified])
        // The unsupported hook is gone; the intro's first sentence stands in.
        ->and($last->content['hook'])->toBe('This channel documents building Chapa Bot in public.')
        ->and($last->verification['partial'])->toBeTrue()
        ->and($last->verification['missing'])->toBe(['hook'])
        ->and($last->month_digests)->toBe($summaries->first()->month_digests);

    // Each retry tells the writer what the fact-checker rejected.
    ProfileWriter::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'A previous draft was rejected')
        && str_contains($prompt->prompt, '- hook: unsupported'));
});

it('keeps a complete live profile instead of a partial new one', function () {
    $channel = channelWithPosts();
    ProcessChannel::dispatchSync($channel->id);
    $live = Summary::where('channel_id', $channel->id)->where('status', SummaryStatus::Verified)->sole();

    $this->fakeAi(['hook' => 'unsupported']);
    ProcessChannel::dispatchSync($channel->id, 'summary_start');

    expect($live->fresh()->status)->toBe(SummaryStatus::Verified)
        ->and(Summary::where('channel_id', $channel->id)->where('id', '>', $live->id)->pluck('status')->unique()->all())->toBe([SummaryStatus::FailedVerification]);
});

it('leaves a draft with nothing left for a person to review', function () {
    $this->fakeAi(['hook' => 'unsupported', 'intro' => 'unsupported'], writer: function (string $prompt) {
        $posts = $this->payload($prompt)['posts'];

        return ['hook' => 'Unbacked.', 'hook_cites' => [$posts[0]['msg_id']], 'vibe_tags' => [], 'intro' => 'Unbacked too.', 'intro_cites' => [$posts[1]['msg_id']],
            'building_now' => [], 'eras' => [], 'start_here' => [], 'in_their_words' => [], 'how_they_work' => null, 'fun_facts' => []];
    });
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);

    expect(Summary::where('channel_id', $channel->id)->pluck('status')->unique()->all())->toBe([SummaryStatus::FailedVerification]);
});

it('drops fabricated quotes, unknown projects and wrong numbers deterministically', function () {
    $this->fakeAi(writer: function (string $prompt) {
        $input = $this->payload($prompt);
        $posts = $input['posts'];

        return [
            'hook' => 'A weekly shipper.',
            'hook_cites' => [$posts[0]['msg_id']],
            'vibe_tags' => ['Builder'],
            'intro' => 'Builds Chapa Bot.',
            'intro_cites' => [$posts[0]['msg_id'], 99999],
            'building_now' => [['project_slug' => 'not-a-project', 'line' => 'x', 'cites' => [$posts[0]['msg_id']]]],
            'eras' => [['name' => 'Ghost era', 'from' => '2020-01', 'to' => '2020-02', 'story' => 'x', 'cites' => [99999]]],
            'start_here' => [['msg_id' => 99999, 'why' => 'x']],
            'in_their_words' => [['msg_id' => $posts[0]['msg_id'], 'quote' => 'words that were never written']],
            'how_they_work' => null,
            'fun_facts' => [
                ['text' => 'Posted 4000 times.', 'source' => 'stats.total_posts'],
                ['text' => 'Hello', 'source' => 'stats.not_a_stat'],
            ],
        ];
    });
    $channel = channelWithPosts();

    ProcessChannel::dispatchSync($channel->id);

    $summary = Summary::where('channel_id', $channel->id)->latest('version')->first();

    expect($summary->status)->toBe(SummaryStatus::Verified)
        ->and($summary->citations['intro'])->toBe([1])
        ->and($summary->content['building_now'])->toBe([])
        ->and($summary->content['eras'])->toBe([])
        ->and($summary->content['start_here'])->toBe([])
        ->and($summary->content['in_their_words'])->toBe([])
        ->and($summary->content['fun_facts'])->toBe([]);
});

it('writes in claimed mode with how_they_work once the channel is claimed', function () {
    $creator = Creator::factory()->create(['display_name' => 'Selam']);
    $channel = channelWithPosts(25, ['creator_id' => $creator->id, 'claim_status' => 'claimed']);

    ProcessChannel::dispatchSync($channel->id);

    $summary = Summary::where('channel_id', $channel->id)->latest('version')->first();

    expect($summary->mode)->toBe(SummaryMode::Claimed)
        ->and($summary->creator_id)->toBe($creator->id)
        ->and($summary->content['how_they_work']['text'])->toBe('Laravel every day.');
});

it('excludes sensitive posts from what the writer sees', function () {
    $channel = channelWithPosts();
    Post::where('channel_id', $channel->id)->where('msg_id', 25)->update(['text' => 'A long post about my health and the doctors visits this month']);

    $seen = [];
    $this->fakeAi(writer: function (string $prompt) use (&$seen) {
        $seen = array_column($this->payload($prompt)['posts'], 'msg_id');

        return ['hook' => '', 'hook_cites' => [], 'vibe_tags' => [], 'intro' => '', 'intro_cites' => [], 'building_now' => [], 'eras' => [], 'start_here' => [], 'in_their_words' => [], 'how_they_work' => null, 'fun_facts' => []];
    });

    ProcessChannel::dispatchSync($channel->id);

    expect($seen)->not->toBeEmpty()->not->toContain(25);
});

it('pauses on an exhausted provider quota and resumes from the same step', function () {
    $this->fakeAi();
    config(['buildr.ai.fallback_models' => []]);
    $channel = channelWithPosts();

    PostExtractor::fake(fn () => throw new AiQuotaExhaustedException('tokens per day', 600));

    Queue::fake();
    (new ProcessChannel($channel->id))->handle(
        app(PostExtractionService::class),
        app(ProjectBuilder::class),
        app(StatsCalculator::class),
        app(SummaryGenerator::class),
        app(SummaryVerifier::class),
        app(ContentSelector::class),
        app(AiBudget::class),
    );

    // Rescheduled for later, at the same step, with a resume token.
    Queue::assertPushed(ProcessChannel::class, fn (ProcessChannel $job) => $job->step === 'extract'
        && $job->resumeToken !== null
        && $job->delay !== null);

    $paused = Cache::get(ProcessChannel::PAUSED_KEY);
    expect($paused)->toHaveKey($channel->id)
        ->and($paused[$channel->id]['step'])->toBe('extract');

    // Admin "Resume now": a new token, the delayed copy becomes a no-op.
    $stale = Queue::pushed(ProcessChannel::class)->first();
    expect(ProcessChannel::resumeNow($channel->id))->toBeTrue()
        ->and(Cache::get(ProcessChannel::PAUSED_KEY))->not->toHaveKey($channel->id)
        ->and(Cache::get(ProcessChannel::resumeKey($channel->id)))->not->toBe($stale->resumeToken);
});

it('keeps both sections when two regenerations were asked for from the same version', function () {
    $calls = 0;
    $this->fakeAi(writer: function (string $prompt) use (&$calls) {
        $calls++;
        $posts = $this->payload($prompt)['posts'];

        return [
            'hook' => "Hook from write {$calls}.",
            'hook_cites' => [$posts[0]['msg_id']],
            'vibe_tags' => ['Builder'],
            'intro' => "Intro from write {$calls}.",
            'intro_cites' => [$posts[1]['msg_id']],
            'building_now' => [],
            'eras' => [],
            'start_here' => [],
            'in_their_words' => [],
            'how_they_work' => null,
            'fun_facts' => [],
        ];
    });
    $channel = channelWithPosts();
    ProcessChannel::dispatchSync($channel->id);
    $first = Summary::where('channel_id', $channel->id)->latest('id')->first();

    // Both asked for while $first was live; they run one after the other.
    RegenerateSection::dispatchSync($channel->id, $first->id, 'hook', 'creator');
    RegenerateSection::dispatchSync($channel->id, $first->id, 'intro', 'creator');

    $live = Summary::where('channel_id', $channel->id)->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])->latest('id')->sole();

    expect($live->content['hook'])->toBe('Hook from write 2.')
        ->and($live->content['intro'])->toBe('Intro from write 3.');
});
