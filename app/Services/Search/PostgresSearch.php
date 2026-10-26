<?php

namespace App\Services\Search;

use App\Models\SearchDocument;
use Illuminate\Support\Collection;

/**
 * Production search: pg_trgm similarity on names and a `simple` tsvector on
 * tags/projects/summary text (works for Amharic tokens). Both are indexed
 * (see the directory tables migration).
 */
class PostgresSearch extends SearchService
{
    protected function candidates(string $query, int $limit): Collection
    {
        $nameFields = "'".implode("','", self::NAME_FIELDS)."'";
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';

        return SearchDocument::query()
            ->select('search_documents.*')
            ->selectRaw('similarity(value, ?) as sim', [$query])
            ->selectRaw("ts_rank(tsv, plainto_tsquery('simple', ?)) as rank", [$query])
            ->join('directory_entries', 'directory_entries.id', '=', 'search_documents.directory_entry_id')
            ->whereNotNull('directory_entries.published_at')
            ->where(function ($q) use ($query, $like, $nameFields) {
                $q->where('value', $query)
                    ->orWhereRaw("(field in ({$nameFields}) and (value % ? or value like ?))", [$query, $like])
                    ->orWhereRaw("tsv @@ plainto_tsquery('simple', ?)", [$query]);
            })
            ->orderByDesc('sim')
            ->limit($limit)
            ->get();
    }
}
