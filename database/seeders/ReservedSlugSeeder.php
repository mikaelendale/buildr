<?php

namespace Database\Seeders;

use App\Models\ReservedSlug;
use Illuminate\Database\Seeder;

class ReservedSlugSeeder extends Seeder
{
    /**
     * Offensive / impersonation-prone words that can never be a profile slug.
     * Kept short on purpose; extend from the admin panel.
     */
    private const BLOCKED_WORDS = [
        'fuck', 'shit', 'bitch', 'nazi', 'hitler', 'porn', 'sex', 'xxx', 'nigger', 'faggot',
        'official', 'telegram-official', 'durov', 'support-team', 'moderator', 'staff', 'root',
        'system', 'null', 'undefined',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([...ReservedSlug::DEFAULTS, ...self::BLOCKED_WORDS] as $slug) {
            ReservedSlug::firstOrCreate(['slug' => $slug]);
        }
    }
}
