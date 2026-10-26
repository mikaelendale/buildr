<?php

namespace App\Models;

use App\Enums\PostTone;
use App\Enums\PostType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $post_id
 * @property PostType $type
 * @property array<array-key, mixed>|null $topics
 * @property array<array-key, mixed>|null $projects
 * @property array<array-key, mixed>|null $tools
 * @property string|null $milestone
 * @property PostTone|null $tone
 * @property bool $is_personal_sensitive
 * @property string $model
 * @property int $prompt_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class PostExtraction extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'tone' => PostTone::class,
            'topics' => 'array',
            'projects' => 'array',
            'tools' => 'array',
            'is_personal_sensitive' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
