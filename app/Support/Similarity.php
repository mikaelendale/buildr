<?php

namespace App\Support;

/**
 * Trigram similarity, the same idea as Postgres pg_trgm, so SQLite/local and
 * PHP-side scoring behave like production.
 */
final class Similarity
{
    public static function trigram(string $a, string $b): float
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $ta = self::grams($a);
        $tb = self::grams($b);
        $union = count(array_unique([...$ta, ...$tb]));

        return $union === 0 ? 0.0 : count(array_intersect($ta, $tb)) / $union;
    }

    /**
     * Best similarity of $needle against any word-window of $haystack, so a
     * short query still scores well against a longer name.
     */
    public static function bestWindow(string $needle, string $haystack): float
    {
        $best = self::trigram($needle, $haystack);
        $words = preg_split('/\s+/u', mb_strtolower(trim($haystack))) ?: [];
        $size = max(1, count(preg_split('/\s+/u', trim($needle)) ?: []));

        for ($i = 0; $i + $size <= count($words); $i++) {
            $best = max($best, self::trigram($needle, implode(' ', array_slice($words, $i, $size))));
        }

        return $best;
    }

    /**
     * @return list<string>
     */
    private static function grams(string $s): array
    {
        $grams = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $padded = '  '.$word.' ';

            for ($i = 0; $i < mb_strlen($padded) - 2; $i++) {
                $grams[mb_substr($padded, $i, 3)] = true;
            }
        }

        return array_keys($grams);
    }
}
