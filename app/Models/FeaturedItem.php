<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $featurable_type
 * @property int $featurable_id
 * @property int $sort
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class FeaturedItem extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function featurable(): MorphTo
    {
        return $this->morphTo();
    }
}
