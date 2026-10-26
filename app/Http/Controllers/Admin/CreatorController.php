<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Creator;
use App\Models\FeaturedItem;
use App\Services\Admin\AdminActions;
use App\Services\Claims\SlugService;
use App\Services\Directory\DirectoryIndexer;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/creators (plan §10.4): search, edit, unpublish, feature, ban,
 * force a slug change, merge duplicates, login history.
 */
class CreatorController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q'));

        $creators = Creator::query()
            ->with('user')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('lower(display_name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('lower(real_name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('slug', 'like', '%'.strtolower($search).'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/creators/index', [
            'creators' => $creators->through(fn (Creator $c) => [
                'id' => $c->id,
                'name' => $c->display_name,
                'slug' => $c->slug,
                'status' => $c->status->value,
                'telegram' => $c->user?->telegram_username,
                'banned' => (bool) $c->user?->isBanned(),
                'channels' => $c->channels()->count(),
                'created_at' => $c->created_at?->toIso8601String(),
            ]),
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function show(Creator $creator): Response
    {
        $creator->load(['user.loginEvents' => fn ($q) => $q->latest()->limit(20), 'channels', 'socials']);

        return Inertia::render('admin/creators/show', [
            'creator' => $creator->only(['id', 'slug', 'display_name', 'real_name', 'real_name_am', 'bio', 'location', 'pending_changes', 'review_note']) + [
                'status' => $creator->status->value,
                'published_at' => $creator->published_at?->toIso8601String(),
                'featured' => FeaturedItem::where('featurable_type', 'creator')->where('featurable_id', $creator->id)->exists(),
                'channels' => $creator->channels->map(fn ($c) => ['id' => $c->id, 'username' => $c->username, 'claim_status' => $c->claim_status->value]),
                'socials' => $creator->socials->map(fn ($s) => ['platform' => $s->platform->value, 'url' => $s->url]),
            ],
            'user' => $creator->user === null ? null : [
                'id' => $creator->user->id,
                'name' => $creator->user->name,
                'telegram_username' => $creator->user->telegram_username,
                'banned_at' => $creator->user->banned_at?->toIso8601String(),
                'ban_reason' => $creator->user->ban_reason,
                'created_at' => $creator->user->created_at?->toIso8601String(),
                'last_login_at' => $creator->user->last_login_at?->toIso8601String(),
                'logins' => $creator->user->loginEvents->map(fn ($e) => ['method' => $e->method, 'at' => $e->created_at?->toIso8601String(), 'agent' => $e->user_agent]),
            ],
            'audit' => AuditLog::where('subject_type', 'creator')->where('subject_id', $creator->id)->latest()->limit(30)->with('actor')->get()
                ->map(fn (AuditLog $l) => ['action' => $l->action, 'actor' => $l->actor?->name, 'at' => $l->created_at?->toIso8601String(), 'meta' => $l->meta]),
        ]);
    }

    public function update(Request $request, Creator $creator, DirectoryIndexer $indexer): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:60'],
            'real_name' => ['nullable', 'string', 'max:80'],
            'real_name_am' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:600'],
            'location' => ['nullable', 'string', 'max:80'],
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($creator) {
                $problem = app(SlugService::class)->problem((string) $value, $creator);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ]);

        $before = $creator->only(array_keys($data));

        if ($data['slug'] !== $creator->slug) {
            $data['slug_changed_at'] = now();
        }

        $creator->forceFill($data)->save();
        AuditLog::record('creator.admin_edited', $creator, ['before' => $before, 'after' => $creator->only(array_keys($before))], $request->user());
        $indexer->refreshCreator($creator);

        return $this->done('Creator updated.');
    }

    public function unpublish(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $actions->unpublishCreator($creator, $request->user(), $request->string('reason')->toString() ?: null);

        return $this->done('Profile hidden.');
    }

    public function feature(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $request->boolean('featured', true) ? $actions->feature($creator, $request->user()) : $actions->unfeature($creator, $request->user());

        return $this->done('Featured list updated.');
    }

    public function ban(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        abort_if($creator->user === null, 404);

        if ($request->boolean('unban')) {
            $actions->unban($creator->user, $request->user());

            return $this->done('User unbanned.');
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $actions->ban($creator->user, $request->user(), $data['reason']);

        return $this->done('User banned and profile hidden.');
    }

    public function merge(Request $request, Creator $creator, AdminActions $actions): RedirectResponse
    {
        $data = $request->validate(['into' => ['required', 'string']]);
        $into = Creator::where('slug', strtolower(ltrim($data['into'], '/')))->first();

        abort_if($into === null || $into->id === $creator->id, 422, 'Pick another existing profile to merge into.');

        $actions->merge($creator, $into, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Merged into /{$into->slug}."]);

        return redirect('/admin/creators/'.$into->id);
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
