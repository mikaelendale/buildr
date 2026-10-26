<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $creator_id
 * @property SocialPlatform $platform
 * @property string $url
 * @property string|null $handle
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class CreatorSocial extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
        ];
    }

    /**
     * @return BelongsTo<Creator, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }
}
