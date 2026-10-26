<?php

namespace App\Support;

/**
 * Cheap first pass at spotting paid promos (plan §7.7). Extraction refines it.
 */
final class AdHeuristics
{
    private const MARKERS = [
        '#ad', '#ads', '#sponsored', '#promo', '#реклама', '#partner',
        'sponsored by', 'paid partnership', 'use my code', 'use code', 'promo code', 'discount code',
        'affiliate link', 'ማስታወቂያ', '#ማስታወቂያ',
    ];

    public static function looksLikeAd(?string $text, int $linkCount = 0): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $lower = mb_strtolower($text);

        foreach (self::MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        // Short posts that are mostly a hard call to action plus several links.
        return $linkCount >= 3
            && mb_strlen($lower) < 280
            && preg_match('/\b(join now|subscribe now|sign up now|register now|order now|buy now|limited[- ]time offer|limited offer)\b/u', $lower) === 1;
    }
}
