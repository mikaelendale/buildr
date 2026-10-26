<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $directory_entry_id
 * @property string $field
 * @property string $value
 * @property float $weight
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class SearchDocument extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight' => 'float',
        ];
    }

    /**
     * @return BelongsTo<DirectoryEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(DirectoryEntry::class, 'directory_entry_id');
    }
}
