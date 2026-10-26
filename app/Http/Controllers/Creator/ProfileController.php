<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\CreatorBasicsRequest;
use App\Models\Tag;
use App\Services\Creators\CreatorProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard/profile: basics, socials, bio, tags.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $creator = $request->user()->creator->load(['socials', 'tags']);

        return Inertia::render('dashboard/profile', [
            'basics' => [
                'display_name' => $creator->pending_changes['display_name'] ?? $creator->display_name,
                'real_name' => $creator->pending_changes['real_name'] ?? $creator->real_name,
                'real_name_am' => $creator->pending_changes['real_name_am'] ?? $creator->real_name_am,
                'slug' => $creator->pending_changes['slug'] ?? $creator->slug,
                'bio' => $creator->bio,
                'location' => $creator->location,
                'timezone' => $creator->timezone ?? config('buildr.default_timezone'),
                'socials' => $creator->socials->map(fn ($s) => ['url' => $s->url, 'platform' => $s->platform->value])->values()->all(),
                'tags' => $creator->tags->pluck('name')->all(),
            ],
            'pendingChanges' => array_keys($creator->pending_changes ?? []),
            'isPublished' => $creator->isPublished(),
            'suggestedTags' => Tag::curated()->pluck('name'),
        ]);
    }

    public function update(CreatorBasicsRequest $request, CreatorProfileService $profiles): RedirectResponse
    {
        $pending = $profiles->updateBasics($request->user()->creator, $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $pending === [] ? 'Profile saved.' : 'Saved. Name and link changes go live once a reviewer approves them.',
        ]);

        return back();
    }
}
