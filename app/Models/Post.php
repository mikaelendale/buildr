<?php

namespace App\Models;

use App\Enums\PostLang;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $channel_id
 * @property int $msg_id
 * @property CarbonImmutable|null $posted_at
 * @property bool $edited
 * @property string|null $text
 * @property string|null $text_html
 * @property PostLang|null $lang
 * @property int|null $views
 * @property bool $has_media
 * @property array<array-key, mixed>|null $media_types
 * @property bool $is_forward
 * @property string|null $forwarded_from
 * @property int|null $reply_to_msg_id
 * @property array<array-key, mixed>|null $hashtags
 * @property array<array-key, mixed>|null $reactions
 * @property bool $is_service
 * @property bool $is_ad
 * @property bool $is_deleted
 * @property string|null $content_hash
 * @property CarbonImmutable|null $scraped_at
 * @property CarbonImmutable|null $views_updated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'edited' => 'boolean',
            'lang' => PostLang::class,
            'has_media' => 'boolean',
            'media_types' => 'array',
            'is_forward' => 'boolean',
            'hashtags' => 'array',
            'reactions' => 'array',
            'is_service' => 'boolean',
            'is_ad' => 'boolean',
            'is_deleted' => 'boolean',
            'scraped_at' => 'datetime',
            'views_updated_at' => 'datetime',
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
     * @return HasMany<PostLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(PostLink::class);
    }

    /**
     * @return HasOne<PostExtraction, $this>
     */
    public function extraction(): HasOne
    {
        return $this->hasOne(PostExtraction::class);
    }

    /**
     * Posts worth feeding to the AI: not deleted, not service messages, with text.
     *
     * @param  Builder<self>  $query
     */
    public function scopeContentful(Builder $query): void
    {
        $query->where('is_deleted', false)
            ->where('is_service', false)
            ->whereNotNull('text')
            ->where('text', '!=', '');
    }

    public function telegramUrl(): string
    {
        return 'https://t.me/'.$this->channel->username.'/'.$this->msg_id;
    }
}
