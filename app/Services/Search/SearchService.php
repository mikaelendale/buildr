<?php

namespace App\Services\Search;

use App\Models\DirectoryEntry;
use App\Models\SearchDocument;
use App\Services\Directory\DirectoryIndexer;
use App\Support\Similarity;
use Illuminate\Support\Collection;

/**
 * Directory search with the ranking from plan §9.2:
 *
 *   1. exact channel username   2. exact display/real name (incl. Amharic)
 *   3. trigram similarity ≥ 0.3 on names/usernames/titles
 *   4. full text on tags, projects, summary
 *
 * Boosts: claimed > unclaimed, log(subscribers). Published entries only.
 * Drivers only differ in how candidates are fetched (plan §1.1).
 */
abstract class SearchService
{
    public const NAME_FIELDS = ['display_name', 'real_name', 'real_name_am', 'channel_username', 'channel_title', 'slug'];

    public const TEXT_FIELDS = ['tag', 'project', 'summary'];

    private const MIN_SIMILARITY = 0.3;

    /**
     * Candidate documents for the query, each with optional `sim`/`rank` scores from the DB.
     *
     * @return Collection<int, SearchDocument>
     */
    abstract protected function candidates(string $query, int $limit): Collection;

    public static function normalizeQuery(string $query): string
    {
        return DirectoryIndexer::normalize(str_replace(['@', 'https://', 'http://'], '', trim($query)));
    }

    /**
     * @return Collection<int, DirectoryEntry>
     */
    public function search(string $query, int $limit = 30): Collection
    {
        $q = self::normalizeQuery($query);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        $scores = [];

        foreach ($this->candidates($q, 400) as $doc) {
            $score = $this->score($q, $doc);

            if ($score <= 0) {
                continue;
            }

            $scores[$doc->directory_entry_id] = max($scores[$doc->directory_entry_id] ?? 0, $score);
        }

        if ($scores === []) {
            return collect();
        }

        return DirectoryEntry::query()
            ->visible()
            ->whereIn('id', array_keys($scores))
            ->get()
            ->map(function (DirectoryEntry $entry) use ($scores) {
                $entry->setAttribute('score', $scores[$entry->id]
                    + ($entry->is_claimed ? 50 : 0)
                    + log(1 + $entry->subscribers) * 5);

                return $entry;
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    private function score(string $q, SearchDocument $doc): float
    {
        $value = (string) $doc->value;
        $weight = (float) $doc->weight;

        if ($doc->field === 'channel_username' && $value === ltrim($q, '@')) {
            return 1000;
        }

        if (in_array($doc->field, ['display_name', 'real_name', 'real_name_am', 'slug'], true) && $value === $q) {
            return 800;
        }

        if (in_array($doc->field, self::NAME_FIELDS, true)) {
            $sim = (float) ($doc->getAttribute('sim') ?? Similarity::bestWindow($q, $value));

            if (str_starts_with($value, $q)) {
                $sim = max($sim, 0.75);
            } elseif (str_contains($value, $q)) {
                $sim = max($sim, 0.5);
            }

            if ($sim >= self::MIN_SIMILARITY) {
                return 400 + 200 * $sim + $weight * 10;
            }
        }

        if (in_array($doc->field, self::TEXT_FIELDS, true)) {
            $rank = $doc->getAttribute('rank');

            if ($rank !== null && (float) $rank > 0) {
                return 100 + min(100, (float) $rank * 100) + $weight * 10;
            }

            if ($this->containsAllTerms($value, $q)) {
                return 100 + $weight * 10;
            }
        }

        return 0;
    }

    private function containsAllTerms(string $value, string $q): bool
    {
        foreach (preg_split('/\s+/u', $q) ?: [] as $term) {
            if ($term !== '' && ! str_contains($value, $term)) {
                return false;
            }
        }

        return true;
    }
}
