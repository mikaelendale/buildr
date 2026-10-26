<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DirectoryEntry;
use App\Models\Tag;
use App\Services\Search\SearchService;
use App\Support\TelegramUsername;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $results = $query === '' ? collect() : $this->search->search($query, 40);

        return Inertia::render('public/search', [
            'query' => $query,
            'results' => $results->map->toCard()->values(),
            // Somewhere to go when there's no query or no match.
            'suggestions' => $results->isEmpty()
                ? DirectoryEntry::visible()->orderByDesc('trending_score')->orderByDesc('subscribers')->limit(6)->get()->map->toCard()->values()
                : [],
            'tags' => $results->isEmpty() ? Tag::curated()->limit(12)->get(['slug', 'name']) : [],
            'handle' => TelegramUsername::looksLikeHandle($query) || TelegramUsername::isValid(strtolower(ltrim($query, '@')))
                ? TelegramUsername::normalize($query)
                : null,
        ]);
    }

    /**
     * Instant autocomplete for the hero search bar (GET /api/search/suggest).
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $this->search->search($query, 6)->map(fn (DirectoryEntry $entry) => [
            'name' => $entry->name,
            'url' => $entry->url_path,
            'avatar' => $entry->avatar_path ? Storage::disk('public')->url($entry->avatar_path) : null,
            'channel' => $entry->channels[0]['username'] ?? null,
            'subscribers' => $entry->subscribers,
            'is_claimed' => $entry->is_claimed,
        ]);

        return response()->json(['results' => $results->values()]);
    }
}
