<?php

namespace App\Models;

use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $channel_id
 * @property int|null $creator_id
 * @property string $name
 * @property string $slug
 * @property string $normalized_name
 * @property list<string>|null $aliases
 * @property string|null $url
 * @property string|null $description
 * @property string|null $history
 * @property ProjectStatus $status
 * @property int|null $first_seen_post_id
 * @property int|null $last_seen_post_id
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $last_seen_at
 * @property int $mention_count
 * @property ContentSource $source
 * @property bool $hidden
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Project extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'source' => ContentSource::class,
            'hidden' => 'boolean',
            'aliases' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function firstSeenPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'first_seen_post_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('hidden', false);
    }
}
