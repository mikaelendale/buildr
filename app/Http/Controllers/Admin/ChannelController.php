<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChannelAddedBy;
use App\Enums\ChannelVisibility;
use App\Enums\ScrapeStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\ProcessChannel;
use App\Jobs\Scrape\BackfillChannelPage;
use App\Jobs\Scrape\FetchChannelInfo;
use App\Jobs\Scrape\IncrementalScrape;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\BlockedUsername;
use App\Models\Channel;
use App\Models\ChannelSuggestion;
use App\Models\Post;
use App\Models\Summary;
use App\Services\Admin\AdminActions;
use App\Services\Admin\PipelineStatus;
use App\Services\Claims\OwnershipActions;
use App\Support\TelegramUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/channels (plan §10.3): list, (bulk) add, per-channel tools.
 */
class ChannelController extends Controller
{
    public function index(Request $request, PipelineStatus $pipeline): Response
    {
        $search = trim((string) $request->query('q'));

        $channels = Channel::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('username', 'like', '%'.strtolower(ltrim($search, '@')).'%')
                ->orWhereRaw('lower(title) like ?', ['%'.mb_strtolower($search).'%'])))
            ->when($request->filled('claim'), fn ($q) => $q->where('claim_status', $request->query('claim')))
            ->when($request->filled('visibility'), fn ($q) => $q->where('visibility', $request->query('visibility')))
            ->when($request->filled('scrape'), fn ($q) => $q->where('scrape_status', $request->query('scrape')))
            ->latest('updated_at')
            ->paginate(50)
            ->withQueryString();

        $costs = AiRun::where('subject_type', 'channel')->whereIn('subject_id', $channels->pluck('id'))
            ->groupBy('subject_id')->selectRaw('subject_id, sum(cost_usd_est) as cost')->pluck('cost', 'subject_id');

        $stages = $pipeline->forChannels($channels->getCollection());

        return Inertia::render('admin/channels/index', [
            'channels' => $channels->through(fn (Channel $c) => [
                'id' => $c->id,
                'username' => $c->username,
                'title' => $c->title,
                'subscribers' => $c->subscribers,
                'claim_status' => $c->claim_status->value,
                'visibility' => $c->visibility->value,
                'availability' => $c->availability->value,
                'scrape_status' => $c->scrape_status->value,
                'last_scraped_at' => $c->last_scraped_at?->toIso8601String(),
                'cost' => round((float) ($costs[$c->id] ?? 0), 4),
                'pipeline' => $stages[$c->id] ?? null,
            ]),
            'filters' => $request->only(['q', 'claim', 'visibility', 'scrape']),
            'suggestions' => ChannelSuggestion::where('status', 'open')->latest()->limit(20)->get(['id', 'username', 'created_at']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/channels/create');
    }

    /**
     * Bulk add: paste handles, each is normalized and checked, then scraped +
     * processed; processed channels land in the review queue as unclaimed.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['handles' => ['required', 'string', 'max:20000']]);

        $results = ['added' => [], 'skipped' => []];

        foreach (preg_split('/[\s,]+/', $data['handles']) ?: [] as $raw) {
            if (trim($raw) === '') {
                continue;
            }

            $username = TelegramUsername::normalize($raw);

            $reason = match (true) {
                $username === null => 'not a valid handle',
                BlockedUsername::isBlocked($username) => 'blocklisted',
                Channel::where('username', $username)->exists() => 'already listed',
                default => null,
            };

            if ($reason !== null) {
                $results['skipped'][] = ['handle' => $raw, 'reason' => $reason];

                continue;
            }

            $channel = Channel::create([
                'username' => $username,
                'added_by' => ChannelAddedBy::Admin,
                'visibility' => ChannelVisibility::Hidden,
                'scrape_status' => ScrapeStatus::Queued,
            ]);

            ChannelSuggestion::where('username', $username)->update(['status' => 'added']);
            FetchChannelInfo::dispatch($channel->id, true);
            $results['added'][] = $username;
        }

        AuditLog::record('channels.bulk_added', null, ['added' => $results['added'], 'skipped' => count($results['skipped'])], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => count($results['added']).' added, '.count($results['skipped']).' skipped.']);
        Inertia::flash('bulkResults', $results);

        return redirect('/admin/channels');
    }

    public function show(Channel $channel, PipelineStatus $pipeline): Response
    {
        $channel->load(['creator', 'stats']);

        $posts = Post::where('channel_id', $channel->id)->with('extraction')->latest('msg_id')->paginate(30);

        return Inertia::render('admin/channels/show', [
            'pipeline' => $pipeline->forChannel($channel),
            'aiRuns' => AiRun::where(fn ($q) => $q->where('subject_type', 'channel')->where('subject_id', $channel->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'summary')->whereIn('subject_id', $channel->summaries()->pluck('id')))
                ->latest('id')->limit(12)->get()
                ->map(fn (AiRun $r) => [
                    'id' => $r->id,
                    'job' => $r->job,
                    'model' => $r->model,
                    'status' => $r->status,
                    'tokens' => $r->input_tokens + $r->output_tokens,
                    'duration_ms' => $r->duration_ms,
                    'error' => $r->error !== null ? mb_substr($r->error, 0, 200) : null,
                    'at' => $r->created_at?->toIso8601String(),
                ]),
            'channel' => $channel->only(['id', 'username', 'title', 'description', 'subscribers', 'timezone', 'failure_count', 'last_error', 'backfill_complete', 'newest_msg_id', 'oldest_msg_id']) + [
                'claim_status' => $channel->claim_status->value,
                'visibility' => $channel->visibility->value,
                'availability' => $channel->availability->value,
                'scrape_status' => $channel->scrape_status->value,
                'review_status' => $channel->review_status?->value,
                'creator' => $channel->creator?->only(['id', 'slug', 'display_name']),
                'last_scraped_at' => $channel->last_scraped_at?->toIso8601String(),
                'last_processed_at' => $channel->last_processed_at?->toIso8601String(),
                'stats' => $channel->stats?->stats,
                'post_count' => $channel->posts()->count(),
                'cost' => round((float) AiRun::where(fn ($q) => $q->where('subject_type', 'channel')->where('subject_id', $channel->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'summary')->whereIn('subject_id', $channel->summaries()->pluck('id')))
                    ->sum('cost_usd_est'), 4),
            ],
            'summaries' => $channel->summaries()->orderByDesc('version')->get()->map(fn (Summary $s) => [
                'id' => $s->id,
                'version' => $s->version,
                'status' => $s->status->value,
                'mode' => $s->mode->value,
                'triggered_by' => $s->triggered_by->value,
                'model' => $s->model,
                'created_at' => $s->created_at?->toIso8601String(),
                'content' => $s->content,
                'verification' => $s->verification,
            ]),
            'posts' => $posts->through(fn (Post $p) => [
                'msg_id' => $p->msg_id,
                'posted_at' => $p->posted_at?->toIso8601String(),
                'text' => $p->text,
                'views' => $p->views,
                'lang' => $p->lang?->value,
                'is_forward' => $p->is_forward,
                'is_ad' => $p->is_ad,
                'is_deleted' => $p->is_deleted,
                'extraction' => $p->extraction?->only(['type', 'topics', 'projects', 'tools', 'milestone', 'tone', 'is_personal_sensitive']),
            ]),
        ]);
    }

    public function update(Request $request, Channel $channel, AdminActions $actions): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['nullable', 'timezone:all'],
            'visibility' => ['nullable', 'in:published,hidden'],
        ]);

        if (array_key_exists('timezone', $data)) {
            $channel->forceFill(['timezone' => $data['timezone']])->save();
            AuditLog::record('channel.timezone', $channel, ['timezone' => $data['timezone']], $request->user());
        }

        if (($data['visibility'] ?? null) === 'published') {
            $actions->publishChannel($channel, $request->user());
        } elseif (($data['visibility'] ?? null) === 'hidden') {
            $actions->hideChannel($channel, $request->user());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Channel updated.']);

        return back();
    }

    public function rescrape(Request $request, Channel $channel): RedirectResponse
    {
        $channel->forceFill(['failure_count' => 0, 'last_error' => null, 'scrape_status' => ScrapeStatus::Queued])->save();

        if ($channel->backfill_complete) {
            FetchChannelInfo::dispatch($channel->id);
            IncrementalScrape::dispatch($channel->id);
        } else {
            FetchChannelInfo::dispatch($channel->id);
            BackfillChannelPage::dispatch($channel->id);
        }

        AuditLog::record('channel.rescrape', $channel, [], $request->user());

        return $this->done('Rescrape queued.');
    }

    public function reprocess(Request $request, Channel $channel): RedirectResponse
    {
        ProcessChannel::dispatch($channel->id, 'extract', null, 1, 'admin');
        AuditLog::record('channel.reprocess', $channel, [], $request->user());

        return $this->done('Reprocessing queued.');
    }

    public function destroy(Request $request, Channel $channel, OwnershipActions $ownership): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $ownership->removeChannel($channel->username, $request->user(), $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "@{$channel->username} removed and blocklisted."]);

        return redirect('/admin/channels');
    }

    public function dismissSuggestion(Request $request, ChannelSuggestion $suggestion): RedirectResponse
    {
        $suggestion->update(['status' => 'dismissed']);

        return $this->done('Suggestion dismissed.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
