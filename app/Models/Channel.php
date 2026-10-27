<?php

namespace App\Models;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelAvailability;
use App\Enums\ChannelClaimStatus;
use App\Enums\ChannelReviewStatus;
use App\Enums\ChannelVisibility;
use App\Enums\ScrapeStatus;
use App\Enums\SummaryStatus;
use App\Enums\TelegramPageType;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * @property int $id
 * @property string $username
 * @property string|null $slug
 * @property string|null $title
 * @property string|null $description
 * @property string|null $avatar_path
 * @property int|null $subscribers
 * @property int|null $creator_id
 * @property ChannelClaimStatus $claim_status
 * @property ChannelVisibility $visibility
 * @property bool $page_visible
 * @property bool $show_owner
 * @property ChannelReviewStatus|null $review_status
 * @property ChannelAddedBy $added_by
 * @property ChannelAvailability $availability
 * @property TelegramPageType|null $page_type
 * @property ScrapeStatus $scrape_status
 * @property int|null $newest_msg_id
 * @property int|null $oldest_msg_id
 * @property bool $backfill_complete
 * @property CarbonImmutable|null $last_scraped_at
 * @property CarbonImmutable|null $last_processed_at
 * @property CarbonImmutable|null $last_post_at
 * @property CarbonImmutable|null $unavailable_since
 * @property CarbonImmutable|null $published_at
 * @property string|null $timezone
 * @property int $failure_count
 * @property string|null $last_error
 * @property string|null $card_theme
 * @property string|null $card_color
 * @property CarbonImmutable|null $next_retry_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /**
     * Owner page settings, reset whenever the channel changes hands.
     */
    public const PAGE_DEFAULTS = ['slug' => null, 'page_visible' => true, 'show_owner' => true, 'card_theme' => null, 'card_color' => null];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'page_visible' => true,
        'show_owner' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claim_status' => ChannelClaimStatus::class,
            'visibility' => ChannelVisibility::class,
            'review_status' => ChannelReviewStatus::class,
            'added_by' => ChannelAddedBy::class,
            'availability' => ChannelAvailability::class,
            'page_type' => TelegramPageType::class,
            'scrape_status' => ScrapeStatus::class,
            'backfill_complete' => 'boolean',
            'page_visible' => 'boolean',
            'show_owner' => 'boolean',
            'last_scraped_at' => 'datetime',
            'last_processed_at' => 'datetime',
            'last_post_at' => 'datetime',
            'unavailable_since' => 'datetime',
            'published_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<ChannelSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(ChannelSnapshot::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasOne<ChannelStat, $this>
     */
    public function stats(): HasOne
    {
        return $this->hasOne(ChannelStat::class);
    }

    /**
     * @return HasMany<Summary, $this>
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    /**
     * The newest summary that is allowed on the public page.
     *
     * @return HasOne<Summary, $this>
     */
    public function currentSummary(): HasOne
    {
        return $this->hasOne(Summary::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->whereIn('status', [SummaryStatus::Approved->value, SummaryStatus::Verified->value]),
        );
    }

    /**
     * @return HasOne<Summary, $this>
     */
    public function latestSummary(): HasOne
    {
        return $this->hasOne(Summary::class)->latestOfMany('version');
    }

    /**
     * @return HasMany<SummaryOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(SummaryOverride::class);
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
     * @return HasMany<Claim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublishedUnclaimed(Builder $query): void
    {
        $query->where('visibility', ChannelVisibility::Published)
            ->where('claim_status', ChannelClaimStatus::Unclaimed);
    }

    public function isClaimed(): bool
    {
        return $this->claim_status === ChannelClaimStatus::Claimed;
    }

    public function isRemoved(): bool
    {
        return $this->visibility === ChannelVisibility::Removed;
    }

    public function isPublishedUnclaimed(): bool
    {
        return $this->visibility === ChannelVisibility::Published && ! $this->isClaimed();
    }

    public function telegramUrl(?int $msgId = null): string
    {
        return 'https://t.me/'.$this->username.($msgId !== null ? '/'.$msgId : '');
    }

    /**
     * Every channel has its own page: the owner's short link when they set
     * one, otherwise /c/{username}.
     */
    public function publicPath(): string
    {
        return $this->slug !== null && $this->isClaimed()
            ? '/'.$this->slug
            : '/c/'.$this->username;
    }

    public function publicUrl(): string
    {
        return url($this->publicPath());
    }

    /**
     * Whether anyone can see this channel's page: published unclaimed
     * channels, or claimed ones whose owner is live and kept the page on.
     */
    public function hasPublicPage(): bool
    {
        if ($this->isRemoved()) {
            return false;
        }

        if (! $this->isClaimed()) {
            return $this->visibility === ChannelVisibility::Published;
        }

        $creator = $this->creator;

        if ($creator === null || $creator->user?->isBanned()) {
            return false;
        }

        // While the owner is still onboarding or in review, a page that was
        // already public stays up as it was (claiming must not take it down).
        if (! $creator->isPublished()) {
            return $this->visibility === ChannelVisibility::Published;
        }

        return $this->page_visible;
    }

    /**
     * Claimed channels name their owner unless the owner opted out.
     */
    public function showsOwner(): bool
    {
        // Owner details appear once their page is approved.
        return $this->isClaimed() && $this->creator?->isPublished() === true && $this->show_owner;
    }

    public function displayTitle(): string
    {
        return $this->title ?: '@'.$this->username;
    }

    public function effectiveTimezone(): string
    {
        return $this->timezone
            ?? $this->creator->timezone
            ?? config('buildr.default_timezone');
    }
}
