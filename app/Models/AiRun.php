<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $job
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd_est
 * @property string $status
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class AiRun extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd_est' => 'float',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public static function spentToday(): float
    {
        return (float) self::where('created_at', '>=', now()->startOfDay())->sum('cost_usd_est');
    }
}
