<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DirectoryEntry;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browse grid (plan §9.3): filters + sort in the URL, cursor pagination with
 * Inertia's infinite scroll.
 */
class DiscoverController extends Controller
{
    /**
     * Subscriber ranges [min, max]; null means no upper bound (subscribers
     * is an int column: Postgres rejects PHP_INT_MAX as a bound).
     *
     * @var array<string, array{0: int, 1: int|null}>
     */
    public const SIZES = [
        'lt1k' => [0, 999],
        '1k-10k' => [1_000, 9_999],
        '10k-50k' => [10_000, 49_999],
        '50k+' => [50_000, null],
    ];

    public const SORTS = ['trending', 'newest', 'subscribers', 'active'];

    public function index(Request $request): Response
    {
        return $this->render($request, 'public/discover');
    }

    public function tag(Request $request, Tag $tag): Response
    {
        return $this->render($request, 'public/tag', $tag);
    }

    private function render(Request $request, string $page, ?Tag $tag = null): Response
    {
        $filters = $this->filters($request);

        if ($tag !== null) {
            $filters['tags'] = array_values(array_unique([$tag->slug, ...$filters['tags']]));
        }

        $query = $this->query($filters);

        return Inertia::render($page, [
            'filters' => $filters,
            // The next-page cursor is read from the mapped cards, so they keep the sort columns' raw values.
            'entries' => Inertia::scroll(fn () => $query->cursorPaginate(24)->withQueryString()->through(fn (DirectoryEntry $e) => [
                ...$e->toCard(),
                ...collect(['trending_score', 'published_at', 'subscribers', 'posts_per_week'])->mapWithKeys(fn (string $c) => [$c => $e->getRawOriginal($c)])->all(),
            ])),
            'tags' => Tag::curated()->get(['slug', 'name']),
            'tag' => $tag?->only(['slug', 'name', 'description']),
        ]);
    }

    /**
     * @return array{tags: list<string>, claimed: bool, lang: ?string, active: bool, size: ?string, sort: string}
     */
    private function filters(Request $request): array
    {
        $tags = array_values(array_filter((array) $request->query('tags', []), 'is_string'));

        return [
            'tags' => array_slice($tags, 0, 5),
            'claimed' => $request->boolean('claimed'),
            'lang' => in_array($request->query('lang'), ['en', 'am'], true) ? (string) $request->query('lang') : null,
            'active' => $request->boolean('active'),
            'size' => array_key_exists((string) $request->query('size'), self::SIZES) ? (string) $request->query('size') : null,
            'sort' => in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'trending',
        ];
    }

    /**
     * @param  array{tags: list<string>, claimed: bool, lang: ?string, active: bool, size: ?string, sort: string}  $filters
     * @return Builder<DirectoryEntry>
     */
    private function query(array $filters): Builder
    {
        $query = DirectoryEntry::query()->visible()->withTags($filters['tags']);

        if ($filters['claimed']) {
            $query->where('is_claimed', true);
        }

        if ($filters['lang'] === 'am') {
            $query->whereIn('lang', ['am', 'mixed']);
        } elseif ($filters['lang'] === 'en') {
            $query->whereIn('lang', ['en', 'mixed']);
        }

        if ($filters['active']) {
            $query->where('last_post_at', '>=', now()->subDays(30));
        }

        if ($filters['size'] !== null) {
            [$min, $max] = self::SIZES[$filters['size']];
            $query->where('subscribers', '>=', $min)->when($max !== null, fn (Builder $q) => $q->where('subscribers', '<=', $max));
        }

        return match ($filters['sort']) {
            'newest' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'subscribers' => $query->orderByDesc('subscribers')->orderByDesc('id'),
            'active' => $query->orderByDesc('posts_per_week')->orderByDesc('id'),
            default => $query->orderByDesc('trending_score')->orderByDesc('id'),
        };
    }
}
