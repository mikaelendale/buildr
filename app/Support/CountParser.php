<?php

namespace App\Support;

/**
 * Parses Telegram's human counters: "12.3K", "6.99M", "915", "10 249 235 subscribers".
 */
final class CountParser
{
    public static function parse(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\u{00a0}", "\u{202f}", ' ', ','], '', trim($value));

        if (preg_match('/^(\d+(?:\.\d+)?)([KMB]?)/i', $value, $m) !== 1) {
            return null;
        }

        $multiplier = match (strtoupper($m[2])) {
            'K' => 1_000,
            'M' => 1_000_000,
            'B' => 1_000_000_000,
            default => 1,
        };

        return (int) round((float) $m[1] * $multiplier);
    }
}
