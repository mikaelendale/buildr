<?php

namespace App\Models;

use App\Enums\SponsorshipStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SponsorshipFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A creator's request to sponsor (feature) a claimed channel on the
 * homepage. One row per request, scoped to a channel. An admin approves or
 * rejects; expiry is derived, never persisted (see effectiveStatus()).
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $requested_by
 * @property SponsorshipStatus $status
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Sponsorship extends Model
{
    /** @use HasFactory<SponsorshipFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SponsorshipStatus::class,
            'reviewed_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The user who asked for the promotion (the channel owner at request time).
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Requests waiting on an admin decision.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', SponsorshipStatus::Pending);
    }

    /**
     * Approved sponsorships inside their active window: started and not yet
     * ended. Expired approvals (ends_at in the past) and not-yet-started
     * ones drop out here, so the homepage never shows them.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', SponsorshipStatus::Approved)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * The status that should be shown for this sponsorship. An approved
     * sponsorship whose `ends_at` has passed reads as "expired" even though
     * the stored status stays "approved" — expiry is derived from the time
     * window, never persisted, so no scheduled job is needed to hide it.
     *
     * @return string one of SponsorshipStatus's values, or "expired"
     */
    public function effectiveStatus(): string
    {
        if ($this->status === SponsorshipStatus::Approved
            && $this->ends_at !== null
            && $this->ends_at->isPast()) {
            return 'expired';
        }

        return $this->status->value;
    }
}
