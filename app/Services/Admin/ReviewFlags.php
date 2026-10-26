<?php

namespace App\Services\Admin;

use App\Enums\ChannelClaimStatus;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Creator;
use App\Services\Ai\ContentSelector;
use App\Support\Similarity;

/**
 * Automatic review flags (plan §10.2): possible impersonation, low content,
 * sensitive items dropped by the verifier, new account + huge channel,
 * recently renamed channel.
 */
class ReviewFlags
{
    public function __construct(private readonly ContentSelector $selector) {}

    /**
     * @return list<array{key: string, label: string}>
     */
    public function forCreator(Creator $creator): array
    {
        $flags = [];

        $lookalike = Creator::where('id', '!=', $creator->id)
            ->whereNotNull('published_at')
            ->get(['id', 'display_name', 'slug'])
            ->first(fn (Creator $other) => Similarity::trigram($other->display_name, $creator->display_name) > 0.7);

        if ($lookalike !== null) {
            $flags[] = ['key' => 'impersonation', 'label' => "Name looks like {$lookalike->display_name} (/{$lookalike->slug})"];
        }

        $channels = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->get();

        foreach ($channels as $channel) {
            $flags = [...$flags, ...$this->forChannel($channel)];
        }

        $user = $creator->user;

        if ($user !== null && $user->created_at?->gt(now()->subDays(7)) && $channels->max('subscribers') > 50_000) {
            $flags[] = ['key' => 'new_account_big_channel', 'label' => 'New account claiming a channel with 50K+ subscribers'];
        }

        return $flags;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function forChannel(Channel $channel): array
    {
        $flags = [];

        if ($this->selector->textPostCount($channel) < (int) config('buildr.content.min_text_posts_for_profile')) {
            $flags[] = ['key' => 'low_content', 'label' => "@{$channel->username} has very few text posts"];
        }

        $sensitive = count(array_filter(
            $channel->latestSummary()->first()?->droppedReasons() ?? [],
            fn (string $reason) => str_starts_with($reason, 'sensitive'),
        ));

        if ($sensitive > 0) {
            $flags[] = ['key' => 'sensitive_dropped', 'label' => "Verifier dropped {$sensitive} sensitive item(s) on @{$channel->username}"];
        }

        if (AuditLog::where('action', 'channel.renamed')->where('subject_type', 'channel')->where('subject_id', $channel->id)->where('created_at', '>=', now()->subDays(30))->exists()) {
            $flags[] = ['key' => 'renamed', 'label' => "@{$channel->username} was renamed recently"];
        }

        return $flags;
    }
}
