<?php

namespace App\Services\Admin;

use App\Enums\ChannelAvailability;
use App\Enums\ChannelReviewStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ScrapeStatus;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Summary;
use App\Services\Ai\AiLanes;
use App\Services\Ai\Prompts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where each channel is in the pipeline, for the admin: read from the jobs
 * actually queued or running for it (their payload carries the channel and
 * the AI step), falling back to the channel's own state when nothing is
 * queued. Batched, so a page of 50 channels costs a handful of queries.
 */
class PipelineStatus
{
    /** ProcessChannel steps as the admin reads them. */
    private const STEPS = [
        'extract' => 'Labelling posts',
        'projects' => 'Finding projects',
        'stats' => 'Counting stats',
        'summary_start' => 'Writing digests',
        'summary_map' => 'Writing digests',
        'summary_reduce' => 'Writing profile',
        'verify' => 'Fact-checking',
        'index' => 'Finishing',
        'og' => 'Finishing',
        'done' => 'Finishing',
    ];

    public function __construct(
        private readonly AiLanes $lanes,
        private readonly Prompts $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forChannel(Channel $channel): array
    {
        return $this->forChannels(collect([$channel]))[$channel->id];
    }

    /**
     * @param  Collection<int, Channel>  $channels
     * @return array<int, array<string, mixed>>
     */
    public function forChannels(Collection $channels): array
    {
        $ids = $channels->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $jobs = $this->jobsByChannel();
        $paused = (array) Cache::get(ProcessChannel::PAUSED_KEY, []);

        $labelled = PostExtraction::query()
            ->join('posts', 'posts.id', '=', 'post_extractions.post_id')
            ->whereIn('posts.channel_id', $ids)
            ->where('post_extractions.prompt_version', $this->prompts->version())
            ->groupBy('posts.channel_id')
            ->selectRaw('posts.channel_id, count(*) as n')
            ->pluck('n', 'channel_id');

        $textPosts = Post::whereIn('channel_id', $ids)->where('is_deleted', false)->whereNotNull('text')->where('text', '!=', '')
            ->groupBy('channel_id')->selectRaw('channel_id, count(*) as n')->pluck('n', 'channel_id');

        $posts = Post::whereIn('channel_id', $ids)->groupBy('channel_id')->selectRaw('channel_id, count(*) as n')->pluck('n', 'channel_id');

        $summaries = Summary::whereIn('id', Summary::selectRaw('max(id)')->whereIn('channel_id', $ids)->groupBy('channel_id'))
            ->get()->keyBy('channel_id');

        $lastRuns = AiRun::whereIn('id', AiRun::selectRaw('max(id)')->where('subject_type', 'channel')->whereIn('subject_id', $ids)->groupBy('subject_id'))
            ->get()->keyBy('subject_id');

        $cap = $this->lanes->widest('extraction_max_posts');
        $result = [];

        foreach ($channels as $channel) {
            $target = (int) ($textPosts[$channel->id] ?? 0);
            $target = $cap > 0 ? min($target, $cap) : $target;

            $result[$channel->id] = $this->describe(
                $channel,
                $jobs[$channel->id] ?? [],
                is_array($paused[$channel->id] ?? null) ? $paused[$channel->id] : null,
                (int) ($posts[$channel->id] ?? 0),
                min((int) ($labelled[$channel->id] ?? 0), $target),
                $target,
                $summaries->get($channel->id),
                $lastRuns->get($channel->id),
            );
        }

        return $result;
    }

    /**
     * @param  list<array{name: string, step: ?string, running: bool, attempts: int, available_at: int}>  $jobs
     * @param  array<string, mixed>|null  $paused
     * @return array<string, mixed>
     */
    private function describe(Channel $channel, array $jobs, ?array $paused, int $posts, int $labelled, int $target, ?Summary $summary, ?AiRun $lastRun): array
    {
        // A finished channel's routine refresh doesn't change its stage; it shows as a note.
        $refreshing = $channel->backfill_complete && $jobs !== [] && collect($jobs)->every(fn (array $j) => in_array($j['name'], ['IncrementalScrape', 'RefreshRecentViews'], true));
        $jobs = $refreshing ? [] : $jobs;

        $running = collect($jobs)->firstWhere('running', true);
        $next = $running ?? collect($jobs)->sortBy('available_at')->first();
        $progress = null;
        $detail = $refreshing ? 'Refreshing recent posts' : null;

        [$stage, $tone] = match (true) {
            $channel->availability === ChannelAvailability::NotFound => ['Not on Telegram', 'rose'],
            $channel->availability === ChannelAvailability::NoPreview => ['No public preview', 'amber'],
            $channel->availability !== ChannelAvailability::Ok => ['Unavailable', 'amber'],
            $paused !== null => ['Waiting for AI quota', 'amber'],
            $next !== null && $next['step'] !== null => [self::STEPS[$next['step']] ?? 'Processing', 'sky'],
            $next !== null && $next['name'] === 'RegenerateSection' => ['Rewriting a section', 'sky'],
            $next !== null => ['Scraping', 'sky'],
            $channel->scrape_status === ScrapeStatus::Failed => ['Scrape failed', 'rose'],
            // Nothing queued and no progress for a while: the backfill job died (see failed jobs).
            ! $channel->backfill_complete && $posts > 0 && $channel->last_scraped_at?->lt(now()->subMinutes(15)) => ['Scrape stalled', 'amber'],
            ! $channel->backfill_complete => [$posts === 0 ? 'Queued' : 'Scraping', 'sky'],
            $summary?->status === SummaryStatus::FailedVerification => ['Failed fact-check', 'rose'],
            $summary === null && $labelled < $target => ['Labelling stalled', 'amber'],
            $summary === null => ['Not written yet', 'outline'],
            $channel->isClaimed() => ['Live (claimed)', 'mint'],
            $channel->visibility === ChannelVisibility::Published => ['Live', 'mint'],
            $channel->review_status === ChannelReviewStatus::Rejected => ['Rejected', 'rose'],
            in_array($summary->status, [SummaryStatus::Verified, SummaryStatus::Approved], true) => ['Needs review', 'violet'],
            default => [ucfirst(str_replace('_', ' ', $summary->status->value)), 'outline'],
        };

        if (in_array($stage, ['Labelling posts', 'Labelling stalled'], true) && $target > 0) {
            $progress = ['done' => $labelled, 'total' => $target];
            $detail = "{$labelled} of {$target} posts";
        } elseif (in_array($stage, ['Scraping', 'Queued'], true)) {
            $detail = $posts > 0 ? "{$posts} posts so far" : null;
        } elseif ($stage === 'Writing digests' && $summary?->status === SummaryStatus::Generating) {
            $detail = count((array) $summary->month_digests).' periods digested';
        } elseif ($stage === 'Waiting for AI quota') {
            $until = isset($paused['until']) ? now()->parse((string) $paused['until']) : null;
            $detail = ($until === null || $until->isPast() ? 'Resuming now' : 'Resumes '.$until->diffForHumans()).' at '.(self::STEPS[$paused['step'] ?? ''] ?? 'the next step');
        } elseif ($stage === 'Scrape stalled') {
            $detail = "{$posts} posts, backfill stopped. Use Rescrape.";
        } elseif ($stage === 'Scrape failed') {
            $detail = $channel->last_error;
        } elseif ($stage === 'Failed fact-check') {
            $detail = 'v'.$summary?->version.' was rejected; the last good version stays live';
        }

        return [
            'stage' => $stage,
            'tone' => $tone,
            'detail' => $detail,
            'progress' => $progress,
            'active' => $running !== null,
            'queued' => count($jobs),
            'attempts' => $next['attempts'] ?? 0,
            'posts' => $posts,
            'last_ai' => $lastRun === null ? null : [
                'job' => $lastRun->job,
                'model' => $lastRun->model,
                'status' => $lastRun->status,
                'at' => $lastRun->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Jobs in the database queue, by channel. A reserved job is running now.
     *
     * @return array<int, list<array{name: string, step: ?string, running: bool, attempts: int, available_at: int}>>
     */
    public function jobsByChannel(): array
    {
        $byChannel = [];

        foreach ($this->queuedJobs() as $job) {
            // Avatar downloads ride along on the media queue; they aren't a pipeline stage.
            if ($job['channel_id'] !== null && $job['name'] !== 'StoreChannelAvatar') {
                $byChannel[$job['channel_id']][] = $job;
            }
        }

        return $byChannel;
    }

    /**
     * Every job in the database queue with what it is working on.
     *
     * @return list<array{id: int, queue: string, name: string, channel_id: ?int, step: ?string, running: bool, attempts: int, available_at: int}>
     */
    public function queuedJobs(): array
    {
        if (config('queue.default') !== 'database') {
            return [];
        }

        return array_values(DB::table('jobs')->orderBy('id')->limit(500)->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at'])
            ->map(function (object $row) {
                $payload = (array) json_decode((string) $row->payload, true);
                $command = (string) ($payload['data']['command'] ?? '');

                return [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'name' => class_basename((string) ($payload['displayName'] ?? 'Job')),
                    'channel_id' => preg_match('/"channelId";i:(\d+);/', $command, $m) === 1 ? (int) $m[1] : null,
                    'step' => preg_match('/"step";s:\d+:"([a-z_]+)"/', $command, $m) === 1 ? $m[1] : null,
                    'running' => $row->reserved_at !== null,
                    'attempts' => (int) $row->attempts,
                    'available_at' => (int) $row->available_at,
                ];
            })
            ->all());
    }
}
