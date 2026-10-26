<?php

namespace App\Services\Ai;

use App\Enums\SummaryStatus;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Summary;

/**
 * Weekly regeneration rule (plan §7.5): ≥ 15 new text posts since the last
 * summary, or a new milestone detected.
 */
class RegenerationPolicy
{
    public function __construct(private readonly ContentSelector $selector) {}

    public function shouldRegenerate(Channel $channel): bool
    {
        $last = Summary::where('channel_id', $channel->id)
            ->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved, SummaryStatus::FailedVerification])
            ->latest('version')
            ->first();

        if ($last === null) {
            return $channel->backfill_complete;
        }

        $newPosts = $this->selector->query($channel)->where('posts.created_at', '>', $last->created_at)->get()
            ->filter(fn (Post $p) => $this->selector->hasRealText((string) $p->text))
            ->count();

        if ($newPosts >= (int) config('buildr.content.regen_new_posts_threshold')) {
            return true;
        }

        return PostExtraction::whereIn('post_id', Post::select('id')->where('channel_id', $channel->id))
            ->whereNotNull('milestone')
            ->where('created_at', '>', $last->created_at)
            ->exists();
    }
}
