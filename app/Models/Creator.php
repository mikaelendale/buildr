<?php

namespace App\Models;

use App\Enums\ChannelClaimStatus;
use App\Enums\CreatorStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $slug
 * @property string $display_name
 * @property string|null $real_name
 * @property string|null $real_name_am
 * @property string|null $bio
 * @property string|null $location
 * @property string|null $avatar_path
 * @property int|null $primary_channel_id
 * @property CreatorStatus $status
 * @property string|null $review_note
 * @property array<array-key, mixed>|null $pending_changes
 * @property int $onboarding_step
 * @property CarbonImmutable|null $onboarding_completed_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $slug_changed_at
 * @property string|null $timezone
 * @property string|null $card_theme
 * @property string|null $card_color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Creator extends Model
{
    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CreatorStatus::class,
            'pending_changes' => 'array',
            'onboarding_completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'slug_changed_at' => 'datetime',
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
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function primaryChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'primary_channel_id');
    }

    /**
     * @return HasMany<CreatorSocial, $this>
     */
    public function socials(): HasMany
    {
        return $this->hasMany(CreatorSocial::class)->orderBy('sort');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('source')->withTimestamps();
    }

    /**
     * @return MorphOne<DirectoryEntry, $this>
     */
    public function directoryEntry(): MorphOne
    {
        return $this->morphOne(DirectoryEntry::class, 'listable');
    }

    /**
     * @return MorphOne<FeaturedItem, $this>
     */
    public function featuredItem(): MorphOne
    {
        return $this->morphOne(FeaturedItem::class, 'featurable');
    }

    /**
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->morphMany(Report::class, 'target');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CreatorStatus::Published);
    }

    public function isPublished(): bool
    {
        return $this->status === CreatorStatus::Published;
    }

    public function url(): string
    {
        return url('/'.$this->slug);
    }

    /**
     * The channel whose summary supplies the header (hook + vibe tags).
     */
    public function headerChannel(): ?Channel
    {
        $channels = $this->channels->where('claim_status', ChannelClaimStatus::Claimed);

        return $channels->firstWhere('id', $this->primary_channel_id)
            ?? $channels->sortBy('id')->first();
    }
}
