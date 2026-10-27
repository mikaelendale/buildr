<?php

namespace App\Services\Claims;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelClaimStatus;
use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Enums\TelegramPageType;
use App\Enums\TriggeredBy;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Models\AuditLog;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Claim;
use App\Models\Creator;
use App\Models\Summary;
use App\Models\User;
use App\Services\Directory\DirectoryIndexer;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Og\OgImageRenderer;
use App\Services\Telegram\Exceptions\TelegramFetchException;
use App\Services\Telegram\TelegramWebClient;
use App\Support\TelegramUsername;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ownership proof (plan §6.1–6.2): the creator pastes a code into the channel
 * description (or posts it), and the scraper looks for it.
 */
class ClaimService
{
    /**
     * Crockford base32 without ambiguous characters.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private readonly TelegramWebClient $client,
        private readonly TelegramNotifier $notifier,
        private readonly OwnershipActions $actions,
        private readonly DirectoryIndexer $indexer,
        private readonly OgImageRenderer $og,
    ) {}

    public static function generateCode(): string
    {
        do {
            $code = (string) config('buildr.claim_code_prefix').'-';

            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Claim::where('code', $code)->exists());

        return $code;
    }

    /**
     * Run the pre-checks and return the active claim for this user + channel + purpose.
     */
    public function start(User $user, string $input, ClaimPurpose $purpose = ClaimPurpose::Claim): Claim
    {
        $username = TelegramUsername::normalize($input);

        if ($username === null) {
            throw new ClaimException('Enter a channel @handle, t.me link or URL.');
        }

        if ($purpose === ClaimPurpose::Claim && BlockedUsername::isBlocked($username)) {
            throw new ClaimException("@{$username} was removed from Buildr and can't be listed again.", 'blocked');
        }

        if ($purpose === ClaimPurpose::Removal && BlockedUsername::isBlocked($username)) {
            throw new ClaimException("@{$username} is already removed and blocklisted. Nothing else to do.", 'already_removed');
        }

        $channel = Channel::where('username', $username)->first();
        $creator = $user->creator;

        if ($purpose === ClaimPurpose::Claim && $channel?->isClaimed()) {
            if ($creator !== null && $channel->creator_id === $creator->id) {
                throw new ClaimException("@{$username} is already verified as yours.", 'already_yours');
            }

            throw new ClaimException("@{$username} is already claimed by someone else. If it's yours, you can open a dispute.", 'claimed');
        }

        if ($purpose === ClaimPurpose::Claim && $creator !== null) {
            $owned = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->count();

            if ($owned >= (int) config('buildr.max_channels_per_user')) {
                throw new ClaimException('You already have the maximum number of channels. Contact us to add more.', 'limit');
            }
        }

        $existing = Claim::where('user_id', $user->id)
            ->where('channel_username', $username)
            ->where('purpose', $purpose)
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->guardPending($user);
        $this->ensureIsChannel($username);

        return Claim::create([
            'user_id' => $user->id,
            'channel_username' => $username,
            'channel_id' => $channel?->id,
            'purpose' => $purpose,
            'code' => self::generateCode(),
            'status' => ClaimStatus::Pending,
            'expires_at' => now()->addHours((int) config('buildr.claim_code_ttl_hours')),
        ]);
    }

    /**
     * The owner's channel got a new @handle: a code on the new handle proves
     * it, then completeRename checks the posts match. Blocklisted handles
     * stay blocklisted; asking again returns the same code.
     */
    public function startRename(User $user, Channel $channel, string $new): Claim
    {
        if (BlockedUsername::isBlocked($new)) {
            throw new ClaimException("@{$new} was removed from Buildr and can't be listed again.", 'blocked');
        }

        $existing = Claim::where('user_id', $user->id)
            ->where('channel_id', $channel->id)
            ->where('channel_username', $new)
            ->where('purpose', ClaimPurpose::Rename)
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->guardPending($user);
        $this->ensureIsChannel($new);

        return Claim::create([
            'user_id' => $user->id,
            'channel_username' => $new,
            'channel_id' => $channel->id,
            'purpose' => ClaimPurpose::Rename,
            'code' => self::generateCode(),
            'status' => ClaimStatus::Pending,
            'expires_at' => now()->addHours((int) config('buildr.claim_code_ttl_hours')),
        ]);
    }

    /**
     * Someone tries to claim a channel that is already claimed: both parties
     * get a fresh code; whoever's code shows up in the channel wins (§10.5).
     *
     * @return array{0: Claim, 1: Claim|null}
     */
    public function openDispute(User $challenger, string $input): array
    {
        $username = TelegramUsername::normalize($input);
        $channel = $username !== null ? Channel::where('username', $username)->with('creator.user')->first() : null;

        if ($channel === null || ! $channel->isClaimed()) {
            throw new ClaimException('There is nothing to dispute for this channel.');
        }

        if ($channel->creator?->user_id === $challenger->id) {
            throw new ClaimException("@{$username} is already yours.");
        }

        if (! Claim::where('user_id', $challenger->id)->where('channel_id', $channel->id)->where('purpose', ClaimPurpose::Dispute)->active()->exists()) {
            $this->guardPending($challenger);
        }

        $expires = now()->addHours((int) config('buildr.claim_code_ttl_hours'));

        $mine = Claim::where('user_id', $challenger->id)->where('channel_id', $channel->id)->where('purpose', ClaimPurpose::Dispute)->active()->first()
            ?? Claim::create([
                'user_id' => $challenger->id,
                'channel_username' => $channel->username,
                'channel_id' => $channel->id,
                'purpose' => ClaimPurpose::Dispute,
                'code' => self::generateCode(),
                'status' => ClaimStatus::Pending,
                'expires_at' => $expires,
            ]);

        $owner = $channel->creator?->user;
        $theirs = null;

        if ($owner !== null) {
            $theirs = Claim::where('user_id', $owner->id)->where('channel_id', $channel->id)->where('purpose', ClaimPurpose::Dispute)->active()->first()
                ?? Claim::create([
                    'user_id' => $owner->id,
                    'channel_username' => $channel->username,
                    'channel_id' => $channel->id,
                    'purpose' => ClaimPurpose::Dispute,
                    'code' => self::generateCode(),
                    'status' => ClaimStatus::Pending,
                    'expires_at' => $expires,
                ]);

            // Only when the dispute is new: asking again must not spam the owner.
            if ($theirs->wasRecentlyCreated) {
                $this->notifier->toUser($owner, "⚠️ Someone else says they own <b>@{$channel->username}</b>. To keep it on your profile, put this code in the channel description within 48 hours: <code>{$theirs->code}</code>");
            }
        }

        if ($mine->wasRecentlyCreated) {
            $this->notifier->toAdmin("Dispute opened for <b>@{$channel->username}</b>: ".url('/admin/claims'));
            AuditLog::record('claim.dispute_opened', $channel, ['challenger_id' => $challenger->id], $challenger);
        }

        return [$mine, $theirs];
    }

    /**
     * Look for the code in the channel description, then in the latest posts.
     */
    public function check(Claim $claim): Claim
    {
        if (! $claim->isActive()) {
            return $claim;
        }

        // A banned account's claims (removals, disputes, renames) never complete.
        if ($claim->user?->isBanned()) {
            $claim->forceFill(['status' => ClaimStatus::Failed])->save();

            return $claim;
        }

        $claim->forceFill(['attempts' => $claim->attempts + 1, 'last_checked_at' => now()])->save();

        try {
            // Taking a page away from someone (dispute), deleting it (removal) or
            // moving it (rename) needs the code in the description, which only
            // the channel's admins can edit; a post can come from a forward or
            // an auto-posting bot.
            [$foundIn, $msgId] = $this->findCode($claim->channel_username, $claim->code, postsToo: $claim->purpose === ClaimPurpose::Claim);
        } catch (TelegramFetchException) {
            return $claim;
        }

        if ($foundIn === null) {
            return $claim;
        }

        // Only one request may verify (the page polls while the user also
        // clicks "Check"), and verifying and attaching commit together: a
        // request cut off mid-way (e.g. by a deploy) leaves the claim pending,
        // to be found again on the next check, never verified-but-unattached.
        DB::transaction(function () use ($claim, $foundIn, $msgId) {
            $won = Claim::whereKey($claim->id)->where('status', ClaimStatus::Pending)->update([
                'status' => ClaimStatus::Verified,
                'verified_at' => now(),
                'found_in' => $foundIn,
                'found_msg_id' => $msgId,
            ]);

            if ($won === 1) {
                $this->onVerified($claim->fresh() ?? $claim);
            }
        });

        return $claim->refresh();
    }

    /**
     * Claims that were verified but never attached to their channel (cut off
     * before the attach could commit). Re-runs the attach for each.
     *
     * @return list<string> usernames repaired
     */
    public function repairUnattached(): array
    {
        $repaired = [];

        Claim::where('purpose', ClaimPurpose::Claim)
            ->where('status', ClaimStatus::Verified)
            ->with('user')
            ->each(function (Claim $claim) use (&$repaired) {
                // Attaching always leaves this audit entry; a claimed channel is
                // theirs or a dispute, and a released one stays released.
                $attached = AuditLog::where('action', 'claim.verified')->where('meta->claim_id', $claim->id)->exists();

                if ($attached || Channel::where('username', $claim->channel_username)->first()?->isClaimed()) {
                    return;
                }

                DB::transaction(fn () => $this->onVerified($claim));
                $repaired[] = $claim->channel_username;
            });

        return $repaired;
    }

    /**
     * Where the code shows up: the description, or (for a plain claim) one
     * of the latest posts the channel wrote itself (forwards don't count).
     *
     * @return array{0: string|null, 1: int|null}
     */
    public function findCode(string $username, string $code, bool $postsToo = true): array
    {
        $needle = mb_strtolower($code);
        $info = $this->client->channelInfo($username);

        if (str_contains(mb_strtolower((string) $info->description), $needle)) {
            return ['description', null];
        }

        if (! $postsToo) {
            return [null, null];
        }

        $page = $this->client->feed($username);

        foreach (array_reverse($page->posts) as $post) {
            if (! $post->isForward && str_contains(mb_strtolower((string) $post->text), $needle)) {
                return ['post', $post->msgId];
            }
        }

        return [null, null];
    }

    /**
     * Codes waiting at once, per account: enough for real use, too few to
     * crowd out everyone else's checks.
     */
    public const MAX_PENDING = 10;

    private function guardPending(User $user): void
    {
        if (Claim::where('user_id', $user->id)->active()->count() >= self::MAX_PENDING) {
            throw new ClaimException('You have too many codes waiting. Finish those or let them expire first.', 'too_many');
        }
    }

    /**
     * Expire stale codes (daily schedule).
     */
    public function expireStale(): int
    {
        return Claim::where('status', ClaimStatus::Pending)
            ->where('expires_at', '<=', now())
            ->update(['status' => ClaimStatus::Expired]);
    }

    private function onVerified(Claim $claim): void
    {
        $claim->loadMissing('user');

        match ($claim->purpose) {
            ClaimPurpose::Claim => $this->attach($claim),
            ClaimPurpose::Removal => $this->actions->removeChannel($claim->channel_username, $claim->user, 'Owner removed via claim code'),
            ClaimPurpose::Dispute => $this->resolveDispute($claim),
            ClaimPurpose::Rename => $this->actions->completeRename($claim),
        };
    }

    private function attach(Claim $claim): void
    {
        $user = $claim->user;
        $creator = $user->creator ?? $this->actions->draftCreatorFor($user);

        $channel = Channel::firstOrCreate(
            ['username' => $claim->channel_username],
            ['added_by' => ChannelAddedBy::Self],
        );

        if ($channel->isClaimed() && $channel->creator_id !== $creator->id) {
            $claim->forceFill(['status' => ClaimStatus::Disputed])->save();
            $this->notifier->toAdmin("Claim race on <b>@{$channel->username}</b>: verified code but already claimed. ".url('/admin/claims'));

            return;
        }

        $wasUnclaimedPage = $channel->exists && $channel->backfill_complete;

        $channel->forceFill([
            'creator_id' => $creator->id,
            'claim_status' => ChannelClaimStatus::Claimed,
            'review_status' => null,
        ])->save();

        $claim->forceFill(['channel_id' => $channel->id])->save();

        if ($creator->primary_channel_id === null) {
            $creator->forceFill(['primary_channel_id' => $channel->id])->save();
        }

        $channel->projects()->whereNull('creator_id')->update(['creator_id' => $creator->id]);

        AuditLog::record('claim.verified', $channel, ['claim_id' => $claim->id, 'found_in' => $claim->found_in], $user);

        $this->notifier->toUser($user, "✅ <b>@{$channel->username}</b> is verified as yours. You can remove the code from your channel now.");

        // A channel already in the directory keeps its profile: claiming only
        // proves ownership. Only channels new to Buildr are scraped and written.
        $live = $wasUnclaimedPage ? $channel->currentSummary()->first() : null;

        if ($live !== null) {
            $this->adopt($channel, $creator, $live);
        } elseif ($wasUnclaimedPage) {
            ProcessChannel::dispatch($channel->id, 'summary_start', null, 1, 'creator');
        } else {
            FetchChannelInfo::dispatch($channel->id, true);
        }
    }

    /**
     * The live profile becomes the owner's (claimed mode, same content), so
     * onboarding moves straight on: no rescrape, no AI run.
     */
    private function adopt(Channel $channel, Creator $creator, Summary $live): void
    {
        $copy = $live->replicate(['created_at', 'updated_at']);
        $copy->forceFill([
            'version' => (int) $channel->summaries()->max('version') + 1,
            'mode' => SummaryMode::Claimed,
            'status' => SummaryStatus::Verified,
            'creator_id' => $creator->id,
            'triggered_by' => TriggeredBy::Creator,
            'approved_at' => null,
        ])->save();

        $channel->forceFill(['last_processed_at' => now()])->save();

        $this->indexer->refreshChannel($channel);
        $this->og->refreshForChannel($channel);
    }

    private function resolveDispute(Claim $winner): void
    {
        $channel = Channel::with('creator.user')->find($winner->channel_id);

        if ($channel === null) {
            return;
        }

        Claim::where('channel_id', $channel->id)
            ->where('purpose', ClaimPurpose::Dispute)
            ->where('id', '!=', $winner->id)
            ->where('status', ClaimStatus::Pending)
            ->update(['status' => ClaimStatus::Failed]);

        $owner = $channel->creator?->user;

        if ($owner !== null && $owner->id === $winner->user_id) {
            $this->notifier->toUser($owner, "✅ Dispute resolved: <b>@{$channel->username}</b> stays on your profile.");
            AuditLog::record('claim.dispute_kept', $channel, ['winner_id' => $owner->id], $owner);

            return;
        }

        $this->actions->transferChannel($channel, $winner->user);

        if ($owner !== null) {
            $this->notifier->toUser($owner, "<b>@{$channel->username}</b> was moved to another profile after a dispute: their code appeared in the channel.");
        }

        $this->notifier->toUser($winner->user, "✅ Dispute resolved: <b>@{$channel->username}</b> is now on your profile.");
        AuditLog::record('claim.dispute_transferred', $channel, ['winner_id' => $winner->user_id, 'loser_id' => $owner?->id], $winner->user);
    }

    private function ensureIsChannel(string $username): void
    {
        try {
            $info = $this->client->channelInfo($username);
        } catch (ClaimException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ClaimException("We couldn't reach Telegram just now. Try again in a minute.", 'unreachable');
        }

        if ($info->pageType === TelegramPageType::NotFound) {
            throw new ClaimException("We couldn't find @{$username} on Telegram.", 'not_found');
        }

        if (! $info->isChannel()) {
            throw new ClaimException("@{$username} is a {$info->pageType->value}, not a channel. Only public channels can be listed.", 'not_channel');
        }
    }
}
