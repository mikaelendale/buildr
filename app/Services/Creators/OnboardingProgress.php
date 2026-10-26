<?php

namespace App\Services\Creators;

use App\Enums\ChannelAvailability;
use App\Enums\ChannelClaimStatus;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Services\Ai\ContentSelector;
use App\Services\Ai\PostExtractionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Live progress for a channel being added (plan §6.3): scraping posts →
 * reading posts → finding projects → writing your profile. Used by the
 * onboarding step for the first channel and by the dashboard for every
 * channel added later.
 */
class OnboardingProgress
{
    /** How long a freshly finished channel keeps showing its "ready" card. */
    private const FINISHED_FOR_MINUTES = 30;

    public function __construct(
        private readonly ContentSelector $selector,
        private readonly PostExtractionService $extraction,
    ) {}

    /**
     * Progress of the creator's header channel (onboarding step 5).
     *
     * @return array<string, mixed>
     */
    public function for(Creator $creator): array
    {
        $channel = $creator->headerChannel();

        if ($channel === null) {
            return ['ready' => false, 'channel' => null, 'stages' => [], 'basic' => false, 'problem' => 'no_channel'];
        }

        return $this->forChannel($channel);
    }

    /**
     * Every claimed channel still being built, plus ones that finished in
     * the last half hour after being added (so the dashboard can say so).
     *
     * @return list<array<string, mixed>>
     */
    public function building(Creator $creator): array
    {
        return array_values($creator->channels()
            ->where('claim_status', ChannelClaimStatus::Claimed)
            ->orderBy('id')
            ->get()
            ->map(fn (Channel $channel) => $this->forChannel($channel))
            ->filter(fn (array $p) => ! $p['ready'] || $p['finished_recently'])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function forChannel(Channel $channel): array
    {
        $verifiedAt = Claim::where('channel_id', $channel->id)
            ->where('purpose', ClaimPurpose::Claim)
            ->where('status', ClaimStatus::Verified)
            ->latest('verified_at')
            ->value('verified_at');

        $postCount = $channel->posts()->count();
        $scraped = $channel->backfill_complete;
        $pendingExtraction = $scraped && $postCount > 0 ? $this->extraction->pending($channel)->count() : null;
        $toRead = $scraped && $postCount > 0 ? $this->extraction->sample($this->selector->eligible($channel))->count() : null;
        $textPosts = $scraped ? $this->selector->textPostCount($channel) : 0;
        $basic = $scraped && $textPosts < (int) config('buildr.content.min_text_posts_for_profile');

        $summary = $channel->summaries()->where('mode', SummaryMode::Claimed)->latest('version')->first();
        $written = $summary !== null && in_array($summary->status, [SummaryStatus::Verified, SummaryStatus::Approved, SummaryStatus::FailedVerification], true);

        $processed = $channel->last_processed_at !== null
            && ($verifiedAt === null || $channel->last_processed_at->gte($verifiedAt));

        // Verified a while ago but nothing scraped yet: the job is still waiting in the queue.
        $queued = $postCount === 0 && $channel->last_scraped_at === null
            && $verifiedAt !== null && $verifiedAt->lt(now()->subMinute());

        // Waiting for the AI provider's quota (the job resumes on its own).
        $pausedJob = ((array) Cache::get(ProcessChannel::PAUSED_KEY, []))[$channel->id] ?? null;

        $problem = match (true) {
            $channel->availability === ChannelAvailability::NoPreview => 'no_preview',
            $channel->availability === ChannelAvailability::NotFound => 'not_found',
            is_array($pausedJob) && ! $processed => 'ai_paused',
            $channel->last_error !== null && ! $processed => 'error',
            $queued => 'queued',
            default => null,
        };

        $ready = $processed || $problem === 'no_preview';

        return [
            'channel' => [
                'id' => $channel->id,
                'username' => $channel->username,
                'title' => $channel->displayTitle(),
                'avatar' => $channel->avatar_path ? Storage::disk('public')->url($channel->avatar_path) : null,
            ],
            'posts' => $postCount,
            'basic' => $basic,
            'problem' => $problem,
            'resume_at' => is_array($pausedJob) ? ($pausedJob['until'] ?? null) : null,
            'ready' => $ready,
            'finished_recently' => $ready
                && $verifiedAt !== null && $verifiedAt->gt(now()->subDays(2))
                && $channel->last_processed_at?->gt(now()->subMinutes(self::FINISHED_FOR_MINUTES)) === true,
            'stages' => [
                ['key' => 'scraping', 'label' => 'Scraping your public posts', 'done' => $scraped, 'detail' => $postCount > 0 ? "{$postCount} posts" : null],
                ['key' => 'reading', 'label' => 'Reading posts', 'done' => $scraped && ($basic || $pendingExtraction === 0),
                    'detail' => $toRead && $pendingExtraction !== null && ! $basic ? ($toRead - $pendingExtraction)." of {$toRead}" : null],
                // Claiming an existing page only rewrites the summary, so a finished run counts too.
                ['key' => 'projects', 'label' => 'Finding projects', 'done' => $scraped && ($basic || $processed
                    || $channel->stats()->where('computed_at', '>=', $verifiedAt ?? now()->subYears(10))->exists())],
                ['key' => 'writing', 'label' => 'Writing your profile', 'done' => $processed && ($basic || $written)],
            ],
        ];
    }
}
