<?php

namespace App\Models;

use App\Enums\ContentSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $channel_id
 * @property string $section_key
 * @property array<array-key, mixed>|null $value
 * @property bool $hidden
 * @property ContentSource $source
 * @property int|null $edited_by
 * @property int|null $based_on_summary_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class SummaryOverride extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'hidden' => 'boolean',
            'source' => ContentSource::class,
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
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    /**
     * @return BelongsTo<Summary, $this>
     */
    public function basedOnSummary(): BelongsTo
    {
        return $this->belongsTo(Summary::class, 'based_on_summary_id');
    }
}
