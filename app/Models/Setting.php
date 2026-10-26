<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Small key/value store for runtime switches (scrape kill switch, AI pause,
 * budget overrides). Values are cached briefly; writes bust the cache.
 *
 * @property string $key
 * @property array<array-key, mixed>|null $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
#[WithoutIncrementing]
class Setting extends Model
{
    public const SCRAPING_PAUSED = 'scraping_paused';

    public const AI_PAUSED = 'ai_paused';

    public const AI_DAILY_BUDGET_USD = 'ai_daily_budget_usd';

    public const CANARY_STATUS = 'canary_status';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember('setting:'.$key, 30, function () use ($key) {
            return self::find($key)?->value;
        });

        return $value['v'] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        Cache::forget('setting:'.$key);
    }

    public static function flag(string $key): bool
    {
        return (bool) self::read($key, false);
    }
}
