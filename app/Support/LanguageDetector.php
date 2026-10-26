<?php

namespace App\Support;

use App\Enums\PostLang;

/**
 * Per-post language from script ratios (plan §7.7): Ethiopic → am, Latin → en,
 * a real blend of both → mixed, anything else (Cyrillic, Arabic...) → other.
 */
final class LanguageDetector
{
    public static function detect(?string $text): ?PostLang
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // Drop URLs, handles and hashtags: they are Latin even in Amharic posts.
        $clean = preg_replace('~https?://\S+|[@#][\w]+~u', ' ', $text) ?? $text;

        $ethiopic = preg_match_all('/[\x{1200}-\x{139F}\x{2D80}-\x{2DDF}\x{AB00}-\x{AB2F}]/u', $clean);
        $latin = preg_match_all('/[A-Za-z]/u', $clean);
        $otherLetters = preg_match_all('/[^\P{L}A-Za-z\x{1200}-\x{139F}\x{2D80}-\x{2DDF}\x{AB00}-\x{AB2F}]/u', $clean);

        $letters = $ethiopic + $latin + $otherLetters;

        if ($letters === 0) {
            return null;
        }

        $ethiopicRatio = $ethiopic / $letters;
        $latinRatio = $latin / $letters;

        return match (true) {
            $ethiopicRatio >= 0.6 => PostLang::Am,
            $ethiopicRatio >= 0.15 && $latinRatio >= 0.15 => PostLang::Mixed,
            $latinRatio >= 0.6 => PostLang::En,
            $ethiopicRatio >= 0.15 => PostLang::Mixed,
            default => PostLang::Other,
        };
    }

    /**
     * Ethiopic letters count as "Amharic" for search normalization too.
     */
    public static function hasEthiopic(string $text): bool
    {
        return preg_match('/[\x{1200}-\x{139F}]/u', $text) === 1;
    }
}
