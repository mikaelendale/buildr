<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class ReservedSlug extends Model
{
    /**
     * Seeded list from plan §12.3. Offensive words are added by the seeder too.
     */
    public const DEFAULTS = [
        'admin', 'api', 'login', 'logout', 'register', 'search', 'discover', 'tags', 'tag', 'c',
        'claim', 'remove', 'about', 'terms', 'privacy', 'takedown-policy', 'dashboard', 'onboarding',
        'settings', 'auth', 'telegram', 'og', 'sitemap', 'robots', 'static', 'assets', 'buildr',
        'help', 'support', 'new', 'me', 'u', 'user', 'users', 'up', 'build', 'storage', 'favicon',
        'report', 'reports', 'banned', 'maintenance', 'two-factor-challenge', 'user', 'profile',
    ];

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::DEFAULTS, true)
            || self::where('slug', $slug)->exists();
    }
}
