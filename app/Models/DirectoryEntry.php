<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One row per public channel page (claimed or not). Rebuilt by
 * DirectoryIndexer whenever the channel or its owner changes. creator_id and
 * owner are set only when the owner is shown on the channel.
 *
 * @property int $id
 * @property string $listable_type
 * @property int $listable_id
 * @property int|null $creator_id
 * @property array{name: string, url: string}|null $owner
 * @property string $url_path
 * @property string $name
 * @property string|null $real_name
 * @property string|null $real_name_am
 * @property string|null $avatar_path
 * @property string|null $hook
 * @property array<array-key, mixed>|null $vibe_tags
 * @property array<array-key, mixed>|null $channels
 * @property int $subscribers
 * @property bool $is_claimed
 * @property string|null $lang
 * @property CarbonImmutable|null $last_post_at
 * @property float $posts_per_week
 * @property float $trending_score
 * @property bool $is_available
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class DirectoryEntry extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vibe_tags' => 'array',
            'owner' => 'array',
            'channels' => 'array',
            'is_claimed' => 'boolean',
            'is_available' => 'boolean',
            'last_post_at' => 'datetime',
            'published_at' => 'datetime',
            'posts_per_week' => 'float',
            'trending_score' => 'float',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function listable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<SearchDocument, $this>
     */
    public function searchDocuments(): HasMany
    {
        return $this->hasMany(SearchDocument::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('is_available', true);
    }

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $slugs
     */
    public function scopeWithTags(Builder $query, array $slugs): void
    {
        $creator = (new Creator)->getMorphClass();

        // A channel matches its own tags and its (shown) owner's.
        foreach ($slugs as $slug) {
            $query->whereExists(function ($sub) use ($slug, $creator) {
                $sub->selectRaw('1')
                    ->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('tags.slug', $slug)
                    ->where(fn ($match) => $match
                        ->where(fn ($own) => $own
                            ->whereColumn('taggables.taggable_type', 'directory_entries.listable_type')
                            ->whereColumn('taggables.taggable_id', 'directory_entries.listable_id'))
                        ->orWhere(fn ($owner) => $owner
                            ->where('taggables.taggable_type', $creator)
                            ->whereColumn('taggables.taggable_id', 'directory_entries.creator_id')));
            });
        }
    }

    /**
     * Card payload shared by search, discover, tags and the landing page.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->listable_type,
            'url' => $this->url_path,
            'name' => $this->name,
            'real_name' => $this->real_name,
            'real_name_am' => $this->real_name_am,
            'owner' => $this->owner,
            'avatar' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'hook' => $this->hook,
            'vibe_tags' => $this->vibe_tags ?? [],
            'channels' => $this->channels ?? [],
            'subscribers' => $this->subscribers,
            'is_claimed' => $this->is_claimed,
            'is_available' => $this->is_available,
        ];
    }
}
