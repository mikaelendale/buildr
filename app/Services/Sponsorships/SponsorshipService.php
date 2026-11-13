<?php

namespace App\Services\Sponsorships;

use App\Enums\ChannelClaimStatus;
use App\Enums\SponsorshipStatus;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\Sponsorship;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for homepage sponsorship requests (plan §3).
 *
 * - Creators request promotion for a channel they own and have claimed.
 * - An admin approves or rejects; they may also end an active promotion.
 * - The per-creator active cap is enforced inside a transaction with a
 *   SELECT … FOR UPDATE lock on the creator row so concurrent approvals
 *   cannot both pass the count check.
 * - Every successful admin decision writes an audit event and DMs the
 *   creator. Notifications are dispatched after the transaction commits so
 *   the creator is never messaged for a write that ultimately rolled back.
 */
class SponsorshipService
{
    public function __construct(
        private readonly TelegramNotifier $notifier,
    ) {}

    // -------------------------------------------------------------------------
    // Creator actions
    // -------------------------------------------------------------------------

    /**
     * A creator requests homepage placement for one of their claimed channels.
     *
     * Ownership is checked with the same conditions as
     * Creator\ChannelController::owned(): the requesting user must have a
     * creator profile, the channel must be claimed
     * (claim_status = ChannelClaimStatus::Claimed), and the channel must
     * currently belong to that creator.
     *
     * @throws SponsorshipException
     */
    public function request(User $user, Channel $channel): Sponsorship
    {
        $creator = $user->creator;

        // Mirror Creator\ChannelController::owned() exactly.
        if ($creator === null
            || ! $channel->isClaimed()
            || $channel->creator_id !== $creator->id) {
            throw new SponsorshipException(
                'You can only request promotion for a channel you have verified as yours.',
                'not_owner',
            );
        }

        // A pending or approved request already exists (enforced by the DB
        // partial-unique index too, but we give a friendly message first).
        $existing = Sponsorship::where('channel_id', $channel->id)
            ->whereIn('status', [SponsorshipStatus::Pending->value, SponsorshipStatus::Approved->value])
            ->first();

        if ($existing !== null) {
            $label = $existing->status === SponsorshipStatus::Pending
                ? 'a pending request'
                : 'an active sponsorship';

            throw new SponsorshipException(
                "@{$channel->username} already has {$label}. Cancel it before submitting a new one.",
                'duplicate',
            );
        }

        return Sponsorship::create([
            'channel_id' => $channel->id,
            'requested_by' => $user->id,
            'status' => SponsorshipStatus::Pending,
        ]);
    }

    /**
     * A creator cancels their own pending request.
     *
     * @throws SponsorshipException
     */
    public function cancel(User $user, Sponsorship $sponsorship): void
    {
        // Only the user who submitted the request may cancel it.
        if ($sponsorship->requested_by !== $user->id) {
            throw new SponsorshipException(
                'You can only cancel your own sponsorship requests.',
                'not_owner',
            );
        }

        if ($sponsorship->status !== SponsorshipStatus::Pending) {
            throw new SponsorshipException(
                'Only pending requests can be cancelled.',
                'wrong_status',
            );
        }

        $sponsorship->forceFill(['status' => SponsorshipStatus::Cancelled])->save();
    }

    // -------------------------------------------------------------------------
    // Admin actions
    // -------------------------------------------------------------------------

    /**
     * Approve a pending sponsorship request.
     *
     * The per-creator active cap is enforced inside a transaction with a row
     * lock on the creator so two concurrent approvals for different channels
     * owned by the same creator cannot both pass the limit check.
     *
     * @throws SponsorshipException
     */
    public function approve(Sponsorship $sponsorship, User $admin): void
    {
        if ($sponsorship->status !== SponsorshipStatus::Pending) {
            throw new SponsorshipException(
                'Only pending requests can be approved.',
                'wrong_status',
            );
        }

        // Resolve the channel's current owner. The channel may have been
        // released since the request was made; we always count the current
        // owner's active sponsorships, not the requesting user's.
        $channel = $sponsorship->channel()->with('creator')->first();
        $creator = $channel?->creator;

        DB::transaction(function () use ($sponsorship, $admin, $creator) {
            // Lock the creator row so concurrent approve calls for this
            // creator's channels queue behind each other. On SQLite (tests)
            // lockForUpdate is a no-op and the count still validates correctly.
            if ($creator !== null) {
                Creator::whereKey($creator->id)->lockForUpdate()->first();

                $active = Sponsorship::active()
                    ->whereIn(
                        'channel_id',
                        Channel::where('creator_id', $creator->id)
                            ->where('claim_status', ChannelClaimStatus::Claimed)
                            ->select('id'),
                    )
                    ->count();

                $cap = (int) config('buildr.sponsorships.max_active_per_creator', 1);

                if ($active >= $cap) {
                    throw new SponsorshipException(
                        'This creator already has the maximum number of active sponsored placements.',
                        'cap_reached',
                    );
                }
            }

            // Atomic transition: fail silently if another request raced us.
            $changed = Sponsorship::whereKey($sponsorship->id)
                ->where('status', SponsorshipStatus::Pending)
                ->update([
                    'status' => SponsorshipStatus::Approved,
                    'starts_at' => now(),
                    'ends_at' => null,
                    'reviewed_at' => now(),
                ]);

            if ($changed === 0) {
                throw new SponsorshipException(
                    'This request was already decided.',
                    'already_decided',
                );
            }

            AuditLog::record(
                'sponsorship.approved',
                $sponsorship,
                ['channel_id' => $sponsorship->channel_id],
                $admin,
            );
        });

        // Notify after the transaction commits so a rollback never triggers a DM.
        $sponsorship->refresh();
        $this->notifyApproved($sponsorship);
    }

    /**
     * Reject a pending request with a required reason.
     *
     * @throws SponsorshipException
     */
    public function reject(Sponsorship $sponsorship, User $admin, string $reason): void
    {
        if ($sponsorship->status !== SponsorshipStatus::Pending) {
            throw new SponsorshipException(
                'Only pending requests can be rejected.',
                'wrong_status',
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new SponsorshipException(
                'A reason is required when rejecting a sponsorship request.',
                'reason_required',
            );
        }

        $changed = Sponsorship::whereKey($sponsorship->id)
            ->where('status', SponsorshipStatus::Pending)
            ->update([
                'status' => SponsorshipStatus::Rejected,
                'review_note' => $reason,
                'reviewed_at' => now(),
            ]);

        if ($changed === 0) {
            throw new SponsorshipException(
                'This request was already decided.',
                'already_decided',
            );
        }

        AuditLog::record(
            'sponsorship.rejected',
            $sponsorship,
            ['channel_id' => $sponsorship->channel_id, 'reason' => $reason],
            $admin,
        );

        $sponsorship->refresh();
        $this->notifyRejected($sponsorship);
    }

    /**
     * End an active sponsorship (admin action, optional reason).
     *
     * @throws SponsorshipException
     */
    public function end(Sponsorship $sponsorship, User $admin, ?string $reason = null): void
    {
        $sponsorship->refresh();

        if (! $this->isCurrentlyActive($sponsorship)) {
            throw new SponsorshipException(
                'Only active sponsorships can be ended.',
                'wrong_status',
            );
        }

        $changed = Sponsorship::whereKey($sponsorship->id)
            ->where('status', SponsorshipStatus::Approved)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->update([
                'status' => SponsorshipStatus::Cancelled,
                'reviewed_at' => now(),
                'review_note' => $reason ? trim($reason) : null,
            ]);

        if ($changed === 0) {
            throw new SponsorshipException(
                'This sponsorship is no longer active.',
                'wrong_status',
            );
        }

        AuditLog::record(
            'sponsorship.ended',
            $sponsorship,
            array_filter([
                'channel_id' => $sponsorship->channel_id,
                'reason' => $reason ? trim($reason) : null,
            ]),
            $admin,
        );

        $sponsorship->refresh();
        $this->notifyEnded($sponsorship);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Whether this sponsorship is currently inside its active window.
     * Mirrors Sponsorship::scopeActive() for a single instance check.
     */
    private function isCurrentlyActive(Sponsorship $sponsorship): bool
    {
        return $sponsorship->status === SponsorshipStatus::Approved
            && $sponsorship->starts_at !== null
            && $sponsorship->starts_at->isPast()
            && ($sponsorship->ends_at === null || $sponsorship->ends_at->isFuture());
    }

    private function notifyApproved(Sponsorship $sponsorship): void
    {
        $user = $sponsorship->requestedBy;
        $channel = $sponsorship->channel;

        if ($user === null || $channel === null) {
            return;
        }

        $this->notifier->toUser(
            $user,
            "🎉 Your sponsorship request for <b>@{$channel->username}</b> was approved — the channel now appears in the Sponsored section on the Buildr homepage.",
        );
    }

    private function notifyRejected(Sponsorship $sponsorship): void
    {
        $user = $sponsorship->requestedBy;
        $channel = $sponsorship->channel;

        if ($user === null || $channel === null) {
            return;
        }

        $note = $sponsorship->review_note ? "\n\n{$sponsorship->review_note}" : '';

        $this->notifier->toUser(
            $user,
            "Your sponsorship request for <b>@{$channel->username}</b> wasn't approved.{$note}",
        );
    }

    private function notifyEnded(Sponsorship $sponsorship): void
    {
        $user = $sponsorship->requestedBy;
        $channel = $sponsorship->channel;

        if ($user === null || $channel === null) {
            return;
        }

        $this->notifier->toUser(
            $user,
            "Your sponsored placement for <b>@{$channel->username}</b> has ended and the channel is no longer featured on the Buildr homepage.",
        );
    }
}
