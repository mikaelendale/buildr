<?php

namespace App\Jobs\Ai;

use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Models\Channel;
use App\Models\Summary;
use App\Models\User;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\SummaryGenerator;
use App\Services\Ai\SummaryVerifier;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Og\OgImageRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Regenerate one section: new summary version, verified; auto-publishes if
 * verification passes, otherwise lands in the review queue (plan §6.4).
 */
class RegenerateSection implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(
        public int $channelId,
        public int $baseSummaryId,
        public string $section,
        public string $triggeredBy,
        public ?int $userId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(SummaryGenerator $generator, SummaryVerifier $verifier, TelegramNotifier $notifier): void
    {
        $channel = Channel::find($this->channelId);

        // Build on what is live when this runs, not when it was asked for: a
        // second section requested before the first finished must not undo it.
        $base = Summary::where('channel_id', $this->channelId)
            ->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])
            ->latest('id')
            ->first() ?? Summary::find($this->baseSummaryId);

        if ($channel === null || $base === null) {
            return;
        }

        try {
            $summary = $generator->regenerateSection($base, $this->section, TriggeredBy::from($this->triggeredBy));
        } catch (AiBudgetExceededException) {
            $this->release(3600);

            return;
        }

        $summary = $verifier->verify($summary);

        if ($summary->status === SummaryStatus::Verified) {
            Summary::where('channel_id', $channel->id)
                ->where('id', '!=', $summary->id)
                ->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])
                ->update(['status' => SummaryStatus::Superseded]);

            app(DirectoryIndexer::class)->refreshChannel($channel);
            app(OgImageRenderer::class)->refreshForChannel($channel);
        } else {
            $notifier->toAdmin("A regenerated section ({$this->section}) for <b>@{$channel->username}</b> failed verification. ".url('/admin/review'));
        }

        if ($this->userId !== null) {
            $notifier->toUser(User::find($this->userId), $summary->status === SummaryStatus::Verified
                ? "✨ The new “{$this->section}” section for @{$channel->username} is ready."
                : "We couldn't verify the new “{$this->section}” section for @{$channel->username}, so a person will review it first.");
        }
    }
}
