<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\Creator;
use App\Models\DirectoryEntry;
use App\Models\FeaturedItem;
use App\Models\Tag;
use App\Services\Search\SearchService;
use App\Support\TelegramUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Featured ordering, tag curation, blocklist and the audit log (plan §10.9).
 */
class CurationController extends Controller
{
    public function featured(Request $request, SearchService $search): Response
    {
        $items = FeaturedItem::orderBy('sort')->get()->map(function (FeaturedItem $item) {
            $entry = DirectoryEntry::where('listable_type', $item->featurable_type)->where('listable_id', $item->featurable_id)->first();

            return ['id' => $item->id, 'type' => $item->featurable_type, 'card' => $entry?->toCard(), 'listable_id' => $item->featurable_id];
        });

        $query = trim((string) $request->query('q'));

        return Inertia::render('admin/featured', [
            'items' => $items,
            'query' => $query,
            'results' => $query === '' ? [] : $search->search($query, 10)->map(fn (DirectoryEntry $e) => $e->toCard() + ['listable_type' => $e->listable_type, 'listable_id' => $e->listable_id])->values(),
        ]);
    }

    public function addFeatured(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:creator,channel'], 'id' => ['required', 'integer']]);
        $model = $data['type'] === 'creator' ? Creator::findOrFail((int) $data['id']) : Channel::findOrFail((int) $data['id']);

        FeaturedItem::firstOrCreate(
            ['featurable_type' => $data['type'], 'featurable_id' => $model->id],
            ['sort' => (int) FeaturedItem::max('sort') + 1, 'created_by' => $request->user()->id],
        );

        AuditLog::record('featured.added', $model, [], $request->user());

        return $this->done('Added to featured.');
    }

    public function removeFeatured(Request $request, FeaturedItem $item): RedirectResponse
    {
        $item->delete();
        AuditLog::record('featured.removed', null, ['type' => $item->featurable_type, 'id' => $item->featurable_id], $request->user());

        return $this->done('Removed from featured.');
    }

    public function reorderFeatured(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        foreach (array_values($data['ids']) as $sort => $id) {
            FeaturedItem::whereKey($id)->update(['sort' => $sort]);
        }

        AuditLog::record('featured.reordered', null, ['ids' => $data['ids']], $request->user());

        return $this->done('Order saved.');
    }

    public function tags(): Response
    {
        return Inertia::render('admin/tags', [
            'tags' => Tag::query()
                ->withCount(['creators', 'channels'])
                ->orderByDesc('is_curated')->orderBy('sort')->orderBy('name')
                ->get()
                ->map(fn (Tag $t) => $t->only(['id', 'slug', 'name', 'description', 'is_curated', 'sort']) + ['uses' => $t->creators_count + $t->channels_count]),
        ]);
    }

    public function updateTag(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:300'],
            'is_curated' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $tag->update($data + ['is_curated' => $request->boolean('is_curated')]);
        AuditLog::record('tag.updated', null, ['tag' => $tag->slug] + $data, $request->user());

        return $this->done('Tag saved.');
    }

    /**
     * Merge a duplicate (often AI-made) tag into another one.
     */
    public function mergeTag(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate(['into' => ['required', 'integer', 'different:'.$tag->id]]);
        $into = Tag::findOrFail((int) $data['into']);

        DB::transaction(function () use ($tag, $into) {
            foreach (DB::table('taggables')->where('tag_id', $tag->id)->get() as $row) {
                DB::table('taggables')->insertOrIgnore([
                    'tag_id' => $into->id,
                    'taggable_type' => $row->taggable_type,
                    'taggable_id' => $row->taggable_id,
                    'source' => $row->source,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $tag->delete();
        });

        AuditLog::record('tag.merged', null, ['from' => $tag->slug, 'into' => $into->slug], $request->user());

        return $this->done("Merged into {$into->name}.");
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:30']]);
        $tag = Tag::findOrCreateByName($data['name']);
        $tag->update(['is_curated' => true]);

        return $this->done("Tag {$tag->name} is curated.");
    }

    public function blocklist(): Response
    {
        return Inertia::render('admin/blocklist', [
            'entries' => BlockedUsername::with('creator')->latest()->get()->map(fn (BlockedUsername $b) => [
                'id' => $b->id,
                'username' => $b->username,
                'reason' => $b->reason,
                'by' => $b->creator?->name,
                'created_at' => $b->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function block(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:255']]);
        $username = TelegramUsername::normalize($data['username']);

        abort_if($username === null, 422, 'Invalid username.');

        BlockedUsername::block($username, $data['reason'] ?? null, $request->user()->id);
        AuditLog::record('blocklist.added', null, ['username' => $username, 'reason' => $data['reason'] ?? null], $request->user());

        return $this->done("@{$username} blocklisted.");
    }

    public function unblock(Request $request, BlockedUsername $entry): RedirectResponse
    {
        $entry->delete();
        AuditLog::record('blocklist.removed', null, ['username' => $entry->username], $request->user());

        return $this->done("@{$entry->username} can be scraped again.");
    }

    public function audit(Request $request): Response
    {
        $logs = AuditLog::with('actor')
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', Str::of((string) $request->query('action'))->replace('%', '')->toString().'%'))
            ->when($request->filled('actor'), fn ($q) => $q->where('actor_id', $request->integer('actor')))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/audit', [
            'logs' => $logs->through(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'actor' => $l->actor?->name,
                'actor_id' => $l->actor_id,
                'subject' => $l->subject_type ? "{$l->subject_type}#{$l->subject_id}" : null,
                'meta' => $l->meta,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'filters' => $request->only(['action', 'actor']),
        ]);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
