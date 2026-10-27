<?php

namespace App\Jobs\Ai;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelReviewStatus;
use App\Enums\ChannelVisibility;
use App\Enums\CreatorStatus;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Models\Channel;
use App\Models\Summary;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ContentSelector;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\PostExtractionService;
use App\Services\Ai\ProjectBuilder;
use App\Services\Ai\StatsCalculator;
use App\Services\Ai\SummaryGenerator;
use App\Services\Ai\SummaryVerifier;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Og\OgImageRenderer;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The AI pipeline for one channel (plan §7.4 / §8):
 *
 *   extract → projects → stats → summary_start → summary_map → summary_reduce
 *           → verify → index → og → done
 *
 * Each run does one bounded step and dispatches the next, so no single job
 * runs for long, budget pauses resume cleanly, and a crash retries one step.
 */
class ProcessChannel implements ShouldQueue
{
    use Queueable;

    /**
     * Waiting is not failing: a step re-queues itself while another run holds
     * the channel lock or the AI budget is spent, so only real errors count.
     */
    public int $maxExceptions = 3;

    public int $timeout = 900;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /** Channels waiting for AI quota: channel id → the step to resume. */
    public const PAUSED_KEY = 'ai-quota:paused-channels';

    /** Latest pause, for the admin banner: until + provider message. */
    public const PAUSE_KEY = 'ai-quota:pause';

    public function __construct(
        public int $channelId,
        public string $step = 'extract',
        public ?int $summaryId = null,
        public int $attempt = 1,
        public string $triggeredBy = 'system',
        /** Set on a rescheduled run; a manual resume issues a new token and the old run exits. */
        public ?string $resumeToken = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        PostExtractionService $extraction,
        ProjectBuilder $projects,
        StatsCalculator $stats,
        SummaryGenerator $generator,
        SummaryVerifier $verifier,
        ContentSelector $selector,
        AiBudget $budget,
    ): void {
        $channel = Channel::with('creator.user')->find($this->channelId);

        if ($channel === null || $channel->isRemoved()) {
            return;
        }

        if ($this->resumeToken !== null) {
            if (Cache::get(self::resumeKey($this->channelId)) !== $this->resumeToken) {
                return; // superseded by a manual resume
            }

            self::forgetPaused($this->channelId);
        }

        $lock = Cache::lock('process-channel:'.$channel->id, $this->timeout);

        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $next = null;

        try {
            $next = match ($this->step) {
                'extract' => $extraction->run($channel) > 0 ? ['extract'] : ['projects'],
                'projects' => $this->then(fn () => $projects->build($channel), ['stats']),
                'stats' => $this->afterStats($channel, $stats, $selector),
                'summary_start' => ['summary_map', $generator->start($channel, TriggeredBy::from($this->triggeredBy))->id],
                'summary_map' => $generator->mapMonths($this->summary()) ? ['summary_map'] : ['summary_reduce'],
                'summary_reduce' => $this->then(fn () => $generator->reduce($this->summary()), ['verify']),
                'verify' => $this->afterVerify($channel, $verifier->verify($this->summary())),
                'index' => $this->then(fn () => app(DirectoryIndexer::class)->refreshChannel($channel), ['og']),
                'og' => $this->then(fn () => app(OgImageRenderer::class)->refreshForChannel($channel), ['done']),
                'done' => $this->then(fn () => $this->finish($channel), null),
                default => null,
            };
        } catch (AiBudgetExceededException $e) {
            Log::channel('buildr')->info("AI paused for @{$channel->username}: {$e->getMessage()}");
            $this->release($budget->secondsUntilReset());
        } catch (AiQuotaExhaustedException $e) {
            // Provider quota spent (e.g. tokens per day): come back when it resets
            // instead of burning retries, and keep the step we were on.
            $this->pauseUntil($channel, $e->retryAfterSeconds + 30, $e->getMessage());
        } finally {
            $lock->release();
        }

        if ($next !== null) {
            self::dispatch(
                $this->channelId,
                $next[0],
                $next[1] ?? $this->summaryId,
                $next[2] ?? $this->attempt,
                $this->triggeredBy,
            );
        }
    }

    public static function resumeKey(int $channelId): string
    {
        return "ai-quota:resume:{$channelId}";
    }

    /**
     * Run a paused channel now (admin "Resume"). The delayed run sees a newer
     * token and exits, so the step runs once.
     */
    public static function resumeNow(int $channelId): bool
    {
        $paused = (array) Cache::get(self::PAUSED_KEY, []);
        $job = $paused[$channelId] ?? null;

        if (! is_array($job)) {
            return false;
        }

        $token = Str::random(16);
        Cache::put(self::resumeKey($channelId), $token, now()->addDays(2));
        self::forgetPaused($channelId);

        self::dispatch($channelId, (string) $job['step'], $job['summary_id'], (int) $job['attempt'], (string) $job['triggered_by'], $token);

        return true;
    }

    private static function forgetPaused(int $channelId): void
    {
        $paused = (array) Cache::get(self::PAUSED_KEY, []);
        unset($paused[$channelId]);
        Cache::forever(self::PAUSED_KEY, $paused);
    }

    private function pauseUntil(Channel $channel, int $seconds, string $reason): void
    {
        $until = now()->addSeconds($seconds);
        $token = Str::random(16);

        Cache::put(self::resumeKey($channel->id), $token, $until->addDays(2));
        Cache::put(self::PAUSE_KEY, ['until' => $until->toIso8601String(), 'reason' => Str::limit($reason, 400)], $until);

        $paused = (array) Cache::get(self::PAUSED_KEY, []);
        $paused[$channel->id] = [
            'step' => $this->step,
            'summary_id' => $this->summaryId,
            'attempt' => $this->attempt,
            'triggered_by' => $this->triggeredBy,
            'until' => $until->toIso8601String(),
        ];
        Cache::forever(self::PAUSED_KEY, $paused);

        Log::channel('buildr')->info("AI quota spent for @{$channel->username} at {$this->step}; resuming at {$until->toTimeString()}.");

        self::dispatch($this->channelId, $this->step, $this->summaryId, $this->attempt, $this->triggeredBy, $token)
            ->delay($until);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(2);
    }

    public function failed(Throwable $e): void
    {
        Channel::whereKey($this->channelId)->update(['last_error' => "AI step {$this->step}: ".mb_substr($e->getMessage(), 0, 500)]);

        app(TelegramNotifier::class)->toAdmin("AI pipeline failed at <b>{$this->step}</b> for channel #{$this->channelId}: ".e(mb_substr($e->getMessage(), 0, 200)));
    }

    /**
     * @return array{0: string}
     */
    private function afterStats(Channel $channel, StatsCalculator $stats, ContentSelector $selector): array
    {
        $stats->compute($channel);

        // Too little text for an AI profile: publish a "basic" profile instead (plan §6.3).
        if ($selector->textPostCount($channel) < (int) config('buildr.content.min_text_posts_for_profile')) {
            return ['index'];
        }

        return ['summary_start'];
    }

    /**
     * @return array{0: string, 1?: int, 2?: int}
     */
    private function afterVerify(Channel $channel, Summary $summary): array
    {
        if ($summary->status === SummaryStatus::Verified) {
            Summary::where('channel_id', $channel->id)
                ->where('id', '!=', $summary->id)
                ->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])
                ->update(['status' => SummaryStatus::Superseded]);

            return ['index'];
        }

        // Required section failed: regenerate once from the same digests (plan §8.7),
        // telling the writer what the fact-checker rejected so it doesn't repeat it.
        if ($this->attempt < 2) {
            /** @var list<array{section: string, reason: ?string}> $dropped */
            $dropped = $summary->verification['dropped'] ?? [];
            $feedback = collect($dropped)
                ->map(fn (array $d) => str_replace(['{', '}', '[', ']'], '', $d['section'].': '.Str::limit((string) $d['reason'], 160)))
                ->unique()
                ->take(12)
                ->values()
                ->all();

            $retry = Summary::create([
                'channel_id' => $summary->channel_id,
                'creator_id' => $summary->creator_id,
                'version' => (int) Summary::where('channel_id', $summary->channel_id)->max('version') + 1,
                'mode' => $summary->mode,
                'status' => SummaryStatus::Generating,
                'model' => $summary->model,
                'prompt_version' => $summary->prompt_version,
                'triggered_by' => $summary->triggered_by,
                'month_digests' => $summary->month_digests,
                'verification' => ['feedback' => $feedback],
            ]);

            return ['summary_reduce', $retry->id, 2];
        }

        app(TelegramNotifier::class)->toAdmin("Summary for <b>@{$channel->username}</b> failed verification twice and is waiting in the review queue.");

        return ['index'];
    }

    private function finish(Channel $channel): void
    {
        $firstRun = $channel->last_processed_at === null;
        $channel->forceFill(['last_processed_at' => now(), 'last_error' => null])->save();

        // Admin-added, unclaimed channels land in the review queue.
        if (! $channel->isClaimed() && $channel->visibility === ChannelVisibility::Hidden && $channel->added_by === ChannelAddedBy::Admin) {
            $channel->forceFill(['review_status' => ChannelReviewStatus::Pending])->save();
        }

        $creator = $channel->creator;

        if ($channel->isClaimed() && $creator !== null && in_array($creator->status, [CreatorStatus::Draft, CreatorStatus::ChangesRequested], true)) {
            app(TelegramNotifier::class)->toUser(
                $creator->user,
                "✨ Your Buildr profile draft for <b>@{$channel->username}</b> is ready. Review it and submit: ".url('/onboarding'),
            );
        } elseif ($firstRun && $channel->isClaimed() && $creator?->isPublished()) {
            // A channel added to a live profile: say so once, not on every refresh.
            app(TelegramNotifier::class)->toUser(
                $creator->user,
                "✨ <b>@{$channel->username}</b> is ready on Buildr.",
                [['text' => 'See its page', 'url' => $channel->publicUrl()]],
            );
        }
    }

    private function summary(): Summary
    {
        return Summary::findOrFail($this->summaryId);
    }

    /**
     * @param  array{0: string}|null  $next
     * @return array{0: string}|null
     */
    private function then(callable $work, ?array $next): ?array
    {
        $work();

        return $next;
    }
}
