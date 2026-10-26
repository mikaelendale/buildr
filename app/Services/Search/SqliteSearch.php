<?php

namespace App\Services\Search;

use App\Models\SearchDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Local-dev search: LIKE on the normalized value, scored in PHP with the same
 * trigram similarity Postgres uses (plan §1.1).
 */
class SqliteSearch extends SearchService
{
    protected function candidates(string $query, int $limit): Collection
    {
        $terms = array_values(array_filter(preg_split('/\s+/u', $query) ?: []));

        return SearchDocument::query()
            ->whereHas('entry', fn (Builder $q) => $q->whereNotNull('published_at'))
            ->where(function (Builder $q) use ($query, $terms) {
                $q->where('value', 'like', '%'.$this->escape($query).'%');

                foreach ($terms as $term) {
                    if (mb_strlen($term) >= 3) {
                        $q->orWhere('value', 'like', '%'.$this->escape(mb_substr($term, 0, 3)).'%');
                    }
                }
            })
            ->limit($limit)
            ->get();
    }

    private function escape(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }
}
