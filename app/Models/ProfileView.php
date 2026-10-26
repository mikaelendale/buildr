<?php

namespace App\Models;

use App\Enums\ChannelClaimStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $viewable_type
 * @property int $viewable_id
 * @property CarbonImmutable $date
 * @property int $views
 * @property int $unique_views
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class ProfileView extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Views of everything a creator owns: their profile and each claimed
     * channel's own page.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, Creator $creator): void
    {
        $channelIds = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->pluck('id');

        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $own) => $own->where('viewable_type', $creator->getMorphClass())->where('viewable_id', $creator->id))
            ->orWhere(fn (Builder $channels) => $channels->where('viewable_type', (new Channel)->getMorphClass())->whereIn('viewable_id', $channelIds)));
    }
}
