<?php

namespace App\Services\Claims;

use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ClaimStatus;
use App\Enums\ContentSource;
use App\Enums\CreatorStatus;
use App\Jobs\Ai\ProcessChannel;
use App\Models\AuditLog;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\FeaturedItem;
use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Og\OgImageRenderer;
use App\Services\Telegram\TelegramWebClient;
use App\Support\TelegramUsername;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything that changes who owns a channel: draft creators, removal +
 * blocklist, dispute transfers, renames and account deletion.
 */
class OwnershipActions
{
    public function __construct(
        private readonly TelegramNotifier $notifier,
        private readonly DirectoryIndexer $indexer,
        private readonly SlugService $slugs,
    ) {}

    public function draftCreatorFor(User $user): Creator
    {
        if ($user->creator !== null) {
            return $user->creator;
        }

        $creator = Creator::create([
            'user_id' => $user->id,
            'slug' => $this->slugs->suggest($user->telegram_username ?? $user->name),
            'display_name' => $user->first_name ?: $user->name,
            'avatar_path' => $user->avatar_path,
            'status' => CreatorStatus::Draft,
            'onboarding_step' => 2,
            'timezone' => $user->timezone ?? config('buildr.default_timezone'),
        ]);

        $user->setRelation('creator', $creator);

        return $creator;
    }

    /**
     * Remove a channel (plan §6.5): hidden as removed, posts + summaries deleted,
     * blocklisted so it is never scraped again.
     */
    public function removeChannel(string $username, ?User $by, string $reason): void
    {
        $username = strtolower($username);
        $channel = Channel::with('creator')->where('username', $username)->first();

        DB::transaction(function () use ($channel, $username, $by, $reason) {
            BlockedUsername::block($username, $reason, $by?->id);

            if ($channel === null) {
                return;
            }

            $creator = $channel->creator;

            $channel->posts()->delete();
            $channel->summaries()->delete();
            $channel->overrides()->delete();
            $channel->projects()->delete();
            $channel->stats()->delete();
            $channel->snapshots()->delete();
            $channel->tags()->detach();
            FeaturedItem::where('featurable_type', 'channel')->where('featurable_id', $channel->id)->delete();

            $this->deleteFiles($channel);

            $channel->forceFill([
                'visibility' => ChannelVisibility::Removed,
                'claim_status' => ChannelClaimStatus::Unclaimed,
                'creator_id' => null,
                ...Channel::PAGE_DEFAULTS,
                'title' => null,
                'description' => null,
                'avatar_path' => null,
                'review_status' => null,
            ])->save();

            $this->indexer->removeEntry($channel);

            if ($creator !== null) {
                if ($creator->primary_channel_id === $channel->id) {
                    $creator->forceFill(['primary_channel_id' => $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->value('id')])->save();
                }

                $this->indexer->refreshCreator($creator);
            }

            AuditLog::record('channel.removed', $channel, ['reason' => $reason], $by);
        });

        if ($by !== null) {
            $this->notifier->toUser($by, "🗑 <b>@{$username}</b> was removed from Buildr and added to our blocklist. We won't read it again.");
        }
    }

    public function transferChannel(Channel $channel, User $to): void
    {
        $old = $channel->creator;
        $creator = $this->draftCreatorFor($to);

        // The short link and page choices were the previous owner's.
        $channel->forceFill(['creator_id' => $creator->id, 'claim_status' => ChannelClaimStatus::Claimed, ...Channel::PAGE_DEFAULTS])->save();
        $channel->projects()->update(['creator_id' => $creator->id]);
        $channel->overrides()->where('source', ContentSource::Creator)->delete();

        if ($creator->primary_channel_id === null) {
            $creator->forceFill(['primary_channel_id' => $channel->id])->save();
        }

        if ($old !== null) {
            if ($old->primary_channel_id === $channel->id) {
                $old->forceFill(['primary_channel_id' => $old->channels()->where('id', '!=', $channel->id)->value('id')])->save();
            }

            $this->indexer->refreshCreator($old);
        }

        ProcessChannel::dispatch($channel->id, 'summary_start', null, 1, 'creator');
    }

    /**
     * Rename (plan §7.6): the creator verified a code on the new handle; we
     * also check that known posts match there before migrating the username.
     */
    public function completeRename(Claim $claim): void
    {
        $channel = Channel::find($claim->channel_id);
        $new = TelegramUsername::normalize($claim->channel_username);

        if ($channel === null || $new === null || Channel::where('username', $new)->where('id', '!=', $channel->id)->exists()) {
            $claim->forceFill(['status' => ClaimStatus::Failed])->save();

            return;
        }

        $known = $channel->posts()->whereNotNull('content_hash')->where('is_deleted', false)->latest('msg_id')->limit(20)->get()->keyBy('msg_id');

        try {
            $page = app(TelegramWebClient::class)->feed($new);
        } catch (Throwable) {
            $claim->forceFill(['status' => ClaimStatus::Pending, 'verified_at' => null])->save();

            return;
        }

        $matches = collect($page->posts)->filter(fn ($p) => $known->get($p->msgId)?->content_hash === $p->contentHash())->count();

        if ($matches < min(5, $known->count())) {
            $claim->forceFill(['status' => ClaimStatus::Failed])->save();
            $this->notifier->toUser($claim->user, "We couldn't confirm that @{$new} is the same channel as @{$channel->username}: its posts don't match. Contact us if this is a mistake.");

            return;
        }

        $old = $channel->username;
        $channel->forceFill(['username' => $new])->save();
        BlockedUsername::where('username', $old)->delete();

        AuditLog::record('channel.renamed', $channel, ['from' => $old, 'to' => $new], $claim->user);

        if ($channel->creator !== null) {
            $this->indexer->refreshCreator($channel->creator);
        }

        $this->notifier->toUser($claim->user, "✅ Your channel is now listed as <b>@{$new}</b>.");
    }

    /**
     * Delete account (plan §6.6): profile + creator overrides + socials go; the
     * user is anonymized; channels become removed + blocklisted (default) or
     * go back to unclaimed pages if the creator opted in.
     */
    public function deleteAccount(User $user, bool $keepChannelsAsUnclaimed): void
    {
        $creator = $user->creator;

        DB::transaction(function () use ($user, $creator, $keepChannelsAsUnclaimed) {
            if ($creator !== null) {
                foreach ($creator->channels as $channel) {
                    $channel->overrides()->where('source', ContentSource::Creator)->delete();

                    if ($keepChannelsAsUnclaimed) {
                        $channel->forceFill(['creator_id' => null, 'claim_status' => ChannelClaimStatus::Unclaimed, 'visibility' => ChannelVisibility::Hidden, ...Channel::PAGE_DEFAULTS])->save();
                        $channel->projects()->update(['creator_id' => null]);
                        $channel->projects()->where('source', ContentSource::Creator)->delete();
                        ProcessChannel::dispatch($channel->id, 'summary_start');
                    } else {
                        $this->removeChannel($channel->username, null, 'Owner deleted their account');
                    }
                }

                $creator->socials()->delete();
                $creator->tags()->detach();
                FeaturedItem::where('featurable_type', 'creator')->where('featurable_id', $creator->id)->delete();
                $this->indexer->removeEntry($creator);
                Storage::disk('public')->delete(OgImageRenderer::path('creator', $creator->id));
                $creator->delete();
            }

            $user->claims()->delete();
            $user->forceFill([
                'name' => 'Deleted user',
                'telegram_id' => null,
                'telegram_username' => null,
                'first_name' => null,
                'last_name' => null,
                'avatar_path' => null,
                'email' => null,
                'password' => null,
                'bot_can_message' => false,
                'remember_token' => Str::random(60),
            ])->save();

            AuditLog::record('account.deleted', $user, ['kept_channels' => $keepChannelsAsUnclaimed], $user);
        });
    }

    private function deleteFiles(Channel $channel): void
    {
        $disk = Storage::disk('public');

        if ($channel->avatar_path !== null) {
            $disk->deleteDirectory(dirname($channel->avatar_path));
        }

        $disk->delete(OgImageRenderer::path('channel', $channel->id));
    }
}
