<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\FeaturedItem;
use App\Models\Tag;
use App\Services\Analytics\ViewTracker;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, ViewTracker $views): Response
    {
        $views->recordPage('/', $request);

        $creator = (new Creator)->getMorphClass();

        // A featured creator shows as their main listed channel.
        $featured = FeaturedItem::orderBy('sort')->limit(12)->get()
            ->map(fn (FeaturedItem $item) => $item->featurable_type === $creator
                ? DirectoryEntry::visible()
                    ->where('creator_id', $item->featurable_id)
                    ->orderByRaw('listable_id = (select primary_channel_id from creators where creators.id = ?) desc', [$item->featurable_id])
                    ->orderByDesc('subscribers')
                    ->first()
                : DirectoryEntry::visible()
                    ->where('listable_type', $item->featurable_type)
                    ->where('listable_id', $item->featurable_id)
                    ->first())
            ->filter()
            ->unique('id')
            ->map(fn (DirectoryEntry $entry) => $entry->toCard())
            ->values();

        return Inertia::render('public/home', [
            'featured' => $featured,
            'trending' => DirectoryEntry::visible()
                ->orderByDesc('trending_score')
                ->orderByDesc('subscribers')
                ->limit(8)
                ->get()
                ->map->toCard(),
            'recent' => DirectoryEntry::visible()
                ->where('is_claimed', true)
                ->latest('published_at')
                ->limit(8)
                ->get()
                ->map->toCard(),
            'tags' => Tag::curated()->get(['slug', 'name']),
            'counts' => [
                'profiles' => DirectoryEntry::visible()->count(),
                'claimed' => DirectoryEntry::visible()->where('is_claimed', true)->count(),
                'visitors' => ViewTracker::pageVisitors('/'),
            ],
        ]);
    }
}
