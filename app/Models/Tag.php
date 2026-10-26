<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_curated
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Tag extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_curated' => 'boolean',
        ];
    }

    /**
     * @return MorphToMany<Creator, $this>
     */
    public function creators(): MorphToMany
    {
        return $this->morphedByMany(Creator::class, 'taggable');
    }

    /**
     * @return MorphToMany<Channel, $this>
     */
    public function channels(): MorphToMany
    {
        return $this->morphedByMany(Channel::class, 'taggable');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurated(Builder $query): void
    {
        $query->where('is_curated', true)->orderBy('sort')->orderBy('name');
    }

    public static function findOrCreateByName(string $name): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $slug = Str::slug($name);

        return self::firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
