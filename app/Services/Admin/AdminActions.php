<?php

namespace App\Services\Admin;

use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelReviewStatus;
use App\Enums\ChannelVisibility;
use App\Enums\CreatorStatus;
use App\Enums\SummaryStatus;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\FeaturedItem;
use App\Models\Summary;
use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Og\OgImageRenderer;
use Illuminate\Support\Facades\DB;

/**
 * Admin decisions (plan §10). Every action writes an audit log and, where a
 * creator is affected, sends them a Telegram DM.
 */
class AdminActions
{
    public function __construct(
        private readonly DirectoryIndexer $indexer,
        private readonly OgImageRenderer $og,
        private readonly TelegramNotifier $notifier,
    ) {}

    public function approveCreator(Creator $creator, User $admin, bool $feature = false): void
    {
        DB::transaction(function () use ($creator, $admin, $feature) {
            $creator->forceFill([
                'status' => CreatorStatus::Published,
                'published_at' => $creator->published_at ?? now(),
                'review_note' => null,
            ])->save();

            foreach ($creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->get() as $channel) {
                $this->approveLatestSummary($channel, $admin);
            }

            if ($feature) {
                $this->feature($creator, $admin);
            }

            AuditLog::record('creator.approved', $creator, ['featured' => $feature], $admin);
        });

        $this->indexer->refreshCreator($creator);
        $this->og->renderForCreator($creator->fresh() ?? $creator);

        $this->notifier->toUser($creator->user, "🎉 You're live: {$creator->url()}\n\nShare it: https://t.me/share/url?url=".urlencode($creator->url()));
    }

    public function requestChanges(Creator $creator, User $admin, string $note): void
    {
        $creator->forceFill(['status' => CreatorStatus::ChangesRequested, 'review_note' => $note])->save();
        AuditLog::record('creator.changes_requested', $creator, ['note' => $note], $admin);

        $this->notifier->toUser($creator->user, "✏️ Your Buildr profile needs a few changes before it goes live:\n\n".e($note)."\n\n".url('/onboarding/review'));
    }

    public function rejectCreator(Creator $creator, User $admin, string $reason): void
    {
        $creator->forceFill(['status' => CreatorStatus::Rejected, 'review_note' => $reason])->save();
        $this->indexer->removeEntry($creator);
        AuditLog::record('creator.rejected', $creator, ['reason' => $reason], $admin);

        $this->notifier->toUser($creator->user, "Your Buildr profile wasn't approved: ".e($reason)."\n\nYou can reply through the report button on any page if you think this is a mistake.");
    }

    public function unpublishCreator(Creator $creator, User $admin, ?string $reason = null): void
    {
        $creator->forceFill(['status' => CreatorStatus::Hidden, 'review_note' => $reason])->save();
        $this->indexer->removeEntry($creator);
        AuditLog::record('creator.unpublished', $creator, ['reason' => $reason], $admin);
    }

    /**
     * Name / real name / slug changes wait for review after publishing (plan §6.4).
     */
    public function approveFieldChanges(Creator $creator, User $admin): void
    {
        $changes = $creator->pending_changes ?? [];

        if (isset($changes['slug']) && $changes['slug'] !== $creator->slug) {
            $changes['slug_changed_at'] = now();
        }

        $creator->forceFill($changes + ['pending_changes' => null])->save();
        $this->indexer->refreshCreator($creator);
        $this->og->renderForCreator($creator);
        AuditLog::record('creator.field_changes_approved', $creator, ['fields' => array_keys($creator->getOriginal('pending_changes') ?? [])], $admin);

        $this->notifier->toUser($creator->user, '✅ Your profile changes are live: '.$creator->url());
    }

    public function rejectFieldChanges(Creator $creator, User $admin, ?string $reason): void
    {
        $fields = array_keys($creator->pending_changes ?? []);
        $creator->forceFill(['pending_changes' => null])->save();
        AuditLog::record('creator.field_changes_rejected', $creator, ['fields' => $fields, 'reason' => $reason], $admin);

        $this->notifier->toUser($creator->user, 'We kept your previous '.implode(', ', $fields).($reason ? ': '.e($reason) : '.'));
    }

    public function publishChannel(Channel $channel, User $admin, bool $feature = false): void
    {
        $channel->forceFill([
            'visibility' => ChannelVisibility::Published,
            'review_status' => ChannelReviewStatus::Approved,
            'published_at' => $channel->published_at ?? now(),
        ])->save();

        $this->approveLatestSummary($channel, $admin);

        if ($feature) {
            $this->feature($channel, $admin);
        }

        AuditLog::record('channel.published', $channel, ['featured' => $feature], $admin);
        $this->indexer->refreshChannel($channel);
        $this->og->renderForChannel($channel);
    }

    public function hideChannel(Channel $channel, User $admin): void
    {
        $channel->forceFill(['visibility' => ChannelVisibility::Hidden])->save();
        $this->indexer->removeEntry($channel);
        AuditLog::record('channel.hidden', $channel, [], $admin);
    }

    public function rejectChannel(Channel $channel, User $admin, ?string $reason): void
    {
        $channel->forceFill(['review_status' => ChannelReviewStatus::Rejected, 'visibility' => ChannelVisibility::Hidden])->save();
        $this->indexer->removeEntry($channel);
        AuditLog::record('channel.rejected', $channel, ['reason' => $reason], $admin);
    }

    /**
     * Approve a summary that failed verification (after edits), or the latest verified one.
     */
    public function approveSummary(Summary $summary, User $admin): void
    {
        Summary::where('channel_id', $summary->channel_id)
            ->where('id', '!=', $summary->id)
            ->whereIn('status', [SummaryStatus::Verified, SummaryStatus::Approved])
            ->update(['status' => SummaryStatus::Superseded]);

        $summary->forceFill(['status' => SummaryStatus::Approved, 'approved_at' => now()])->save();
        AuditLog::record('summary.approved', $summary, ['version' => $summary->version], $admin);

        $this->indexer->refreshChannel($summary->channel);
    }

    public function feature(Channel|Creator $model, User $admin): void
    {
        FeaturedItem::firstOrCreate(
            ['featurable_type' => $model->getMorphClass(), 'featurable_id' => $model->getKey()],
            ['sort' => (int) FeaturedItem::max('sort') + 1, 'created_by' => $admin->id],
        );

        AuditLog::record('featured.added', $model, [], $admin);
    }

    public function unfeature(Channel|Creator $model, User $admin): void
    {
        FeaturedItem::where('featurable_type', $model->getMorphClass())->where('featurable_id', $model->getKey())->delete();
        AuditLog::record('featured.removed', $model, [], $admin);
    }

    /**
     * Ban: blocks login and hides every profile of the user (plan §12.2).
     */
    public function ban(User $user, User $admin, string $reason): void
    {
        $user->forceFill(['banned_at' => now(), 'ban_reason' => $reason])->save();

        if ($user->creator !== null) {
            $this->indexer->removeEntry($user->creator);
        }

        AuditLog::record('user.banned', $user, ['reason' => $reason], $admin);
    }

    public function unban(User $user, User $admin): void
    {
        $user->forceFill(['banned_at' => null, 'ban_reason' => null])->save();

        if ($user->creator !== null) {
            $this->indexer->refreshCreator($user->creator);
        }

        AuditLog::record('user.unbanned', $user, [], $admin);
    }

    /**
     * Merge a duplicate creator profile into another: channels, projects and
     * socials move over, the duplicate is deleted.
     */
    public function merge(Creator $from, Creator $into, User $admin): void
    {
        DB::transaction(function () use ($from, $into, $admin) {
            $from->channels()->update(['creator_id' => $into->id]);
            $from->projects()->update(['creator_id' => $into->id]);

            if ($into->socials()->count() === 0) {
                $from->socials()->update(['creator_id' => $into->id]);
            }

            $into->forceFill(['primary_channel_id' => $into->primary_channel_id ?? $from->primary_channel_id])->save();

            $this->indexer->removeEntry($from);
            FeaturedItem::where('featurable_type', 'creator')->where('featurable_id', $from->id)->delete();
            AuditLog::record('creator.merged', $into, ['from_id' => $from->id, 'from_slug' => $from->slug], $admin);
            $from->delete();
        });

        $this->indexer->refreshCreator($into);
    }

    private function approveLatestSummary(Channel $channel, User $admin): void
    {
        $summary = $channel->summaries()->where('status', SummaryStatus::Verified)->latest('version')->first();

        if ($summary !== null) {
            $summary->forceFill(['status' => SummaryStatus::Approved, 'approved_at' => now()])->save();
        }
    }
}
