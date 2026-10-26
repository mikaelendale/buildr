<?php

namespace App\Http\Controllers\Creator;

use App\Enums\ChannelClaimStatus;
use App\Enums\ContentSource;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Project;
use App\Services\Ai\ProjectBuilder;
use App\Services\Directory\DirectoryIndexer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /dashboard/projects: edit, hide, add, reorder (plan §4). Any creator edit
 * marks the project as creator-owned so the AI never overwrites it.
 */
class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $creator = $request->user()->creator;
        $channels = $creator->channels()->where('claim_status', ChannelClaimStatus::Claimed)->get();

        return Inertia::render('dashboard/projects', [
            'channels' => $channels->map(fn (Channel $c) => [
                'id' => $c->id,
                'username' => $c->username,
                'title' => $c->displayTitle(),
                'projects' => $c->projects()->orderBy('sort')->orderByDesc('mention_count')->get()->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'url' => $p->url,
                    'description' => $p->description,
                    'status' => $p->status->value,
                    'hidden' => $p->hidden,
                    'source' => $p->source->value,
                    'mention_count' => $p->mention_count,
                ])->values(),
            ])->values(),
            'statuses' => ProjectStatus::values(),
        ]);
    }

    public function store(Request $request, DirectoryIndexer $indexer): RedirectResponse
    {
        $data = $this->validated($request, true);
        $channel = $this->ownedChannel($request, (int) $data['channel_id']);

        $base = Str::slug($data['name']) ?: 'project';
        $slug = $base;
        $i = 2;

        while ($channel->projects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        $channel->projects()->create([
            'creator_id' => $channel->creator_id,
            'name' => $data['name'],
            'slug' => $slug,
            'normalized_name' => ProjectBuilder::normalize($data['name']),
            'url' => $data['url'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'source' => ContentSource::Creator,
            'sort' => (int) $channel->projects()->max('sort') + 1,
        ]);

        return $this->done($channel, $indexer, 'Project added.');
    }

    public function update(Request $request, Project $project, DirectoryIndexer $indexer): RedirectResponse
    {
        $channel = $this->ownedChannel($request, $project->channel_id);
        $data = $this->validated($request, false);

        $project->update([
            'name' => $data['name'],
            'url' => $data['url'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'source' => ContentSource::Creator,
        ]);

        AuditLog::record('project.edited', $project);

        return $this->done($channel, $indexer, 'Project saved.');
    }

    public function toggle(Request $request, Project $project, DirectoryIndexer $indexer): RedirectResponse
    {
        $channel = $this->ownedChannel($request, $project->channel_id);
        $project->update(['hidden' => ! $project->hidden]);

        return $this->done($channel, $indexer, $project->hidden ? 'Project hidden.' : 'Project visible.');
    }

    public function destroy(Request $request, Project $project, DirectoryIndexer $indexer): RedirectResponse
    {
        $channel = $this->ownedChannel($request, $project->channel_id);

        abort_unless($project->source === ContentSource::Creator, 403, 'AI-found projects can be hidden, not deleted.');

        $project->delete();

        return $this->done($channel, $indexer, 'Project deleted.');
    }

    public function reorder(Request $request, DirectoryIndexer $indexer): RedirectResponse
    {
        $data = $request->validate(['channel_id' => ['required', 'integer'], 'ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        $channel = $this->ownedChannel($request, (int) $data['channel_id']);

        foreach (array_values($data['ids']) as $i => $id) {
            $channel->projects()->whereKey($id)->update(['sort' => $i]);
        }

        return $this->done($channel, $indexer, 'Order saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'channel_id' => [$creating ? 'required' : 'nullable', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'url' => ['nullable', 'url:http,https', 'max:300'],
            'description' => ['nullable', 'string', 'max:300'],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
        ]);
    }

    private function ownedChannel(Request $request, int $channelId): Channel
    {
        $channel = Channel::find($channelId);

        abort_unless($channel !== null && $channel->isClaimed() && $channel->creator_id === $request->user()->creator?->id, 403);

        return $channel;
    }

    private function done(Channel $channel, DirectoryIndexer $indexer, string $message): RedirectResponse
    {
        $indexer->refreshChannel($channel);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
