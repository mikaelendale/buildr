<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $username
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('scrape_blocklist')]
#[Unguarded]
class BlockedUsername extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function isBlocked(string $username): bool
    {
        return self::where('username', strtolower($username))->exists();
    }

    public static function block(string $username, ?string $reason = null, ?int $userId = null): self
    {
        return self::updateOrCreate(
            ['username' => strtolower($username)],
            ['reason' => $reason, 'created_by' => $userId],
        );
    }
}
