<?php

namespace App\Models;

use App\Enums\ClaimPurpose;
use App\Enums\ClaimStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $channel_username
 * @property int|null $channel_id
 * @property ClaimPurpose $purpose
 * @property string $code
 * @property ClaimStatus $status
 * @property int $attempts
 * @property string|null $found_in
 * @property int|null $found_msg_id
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Claim extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => ClaimPurpose::class,
            'status' => ClaimStatus::class,
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ClaimStatus::Pending)->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->status === ClaimStatus::Pending && $this->expires_at->isFuture();
    }
}
