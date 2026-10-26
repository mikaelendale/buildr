<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Ai\ProcessChannel;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Setting;
use App\Services\Admin\PipelineStatus;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiLanes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Horizon\Horizon;

/**
 * /admin/jobs (plan §10.7) and /admin/ai-costs (§10.8): queue health, failed
 * jobs, the global scrape kill switch, canary status, AI spend and budget.
 */
class OperationsController extends Controller
{
    public function jobs(PipelineStatus $pipeline, AiLanes $lanes): Response
    {
        $jobs = collect($pipeline->queuedJobs());
        $names = Channel::whereIn('id', $jobs->pluck('channel_id')->filter()->unique())->pluck('username', 'id');
        $withChannel = fn (array $job) => [...$job, 'channel' => $job['channel_id'] !== null ? ($names[$job['channel_id']] ?? null) : null];

        return Inertia::render('admin/jobs', [
            // What the workers are doing right now, and what's next.
            'running' => $jobs->where('running', true)->map($withChannel)->values(),
            'waiting' => $jobs->where('running', false)->sortBy('available_at')->take(25)->map($withChannel)->values(),
            'recentAi' => AiRun::latest('id')->limit(20)->get()->map(fn (AiRun $r) => [
                'id' => $r->id,
                'job' => $r->job,
                'model' => $r->model,
                'status' => $r->status,
                'tokens' => $r->input_tokens + $r->output_tokens,
                'duration_ms' => $r->duration_ms,
                'error' => $r->error !== null ? mb_substr($r->error, 0, 160) : null,
                'at' => $r->created_at?->toIso8601String(),
                'channel' => $r->subject_type === 'channel' ? Channel::find($r->subject_id)?->username : null,
            ]),
            'lanes' => $lanes->status(),
            'quota' => $this->quotaPause(),
            'queues' => DB::table('jobs')->select('queue', DB::raw('count(*) as count'))->groupBy('queue')->pluck('count', 'queue'),
            'failed' => DB::table('failed_jobs')->latest('failed_at')->limit(50)->get()->map(fn ($job) => [
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'name' => json_decode($job->payload, true)['displayName'] ?? 'job',
                'error' => mb_substr((string) strtok((string) $job->exception, "\n"), 0, 300),
                'failed_at' => $job->failed_at,
            ]),
            'scrapingPaused' => Setting::flag(Setting::SCRAPING_PAUSED),
            'canary' => Setting::read(Setting::CANARY_STATUS),
            'failingChannels' => Channel::where('scrape_status', 'failed')->limit(20)->get(['id', 'username', 'last_error', 'failure_count']),
            'horizon' => config('queue.default') === 'redis' && class_exists(Horizon::class),
        ]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        AuditLog::record('jobs.retry', null, ['uuid' => $uuid], $request->user());

        return $this->done('Job pushed back onto the queue.');
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        AuditLog::record('jobs.forget', null, ['uuid' => $uuid], $request->user());

        return $this->done('Failed job deleted.');
    }

    public function toggleScraping(Request $request): RedirectResponse
    {
        $paused = ! Setting::flag(Setting::SCRAPING_PAUSED);
        Setting::write(Setting::SCRAPING_PAUSED, $paused);
        AuditLog::record($paused ? 'scraping.paused' : 'scraping.resumed', null, [], $request->user());

        return $this->done($paused ? 'Scraping paused everywhere.' : 'Scraping resumed.');
    }

    public function aiCosts(AiBudget $budget): Response
    {
        $since = now()->subDays(29)->startOfDay();

        $byDay = AiRun::where('created_at', '>=', $since)->get(['created_at', 'cost_usd_est'])
            ->groupBy(fn ($r) => $r->created_at->toDateString())
            ->map(fn ($runs) => round((float) $runs->sum('cost_usd_est'), 4));

        return Inertia::render('admin/ai-costs', [
            'today' => round($budget->spentToday(), 4),
            'budget' => $budget->dailyLimit(),
            'paused' => $budget->isPaused(),
            'byDay' => collect(range(29, 0))->map(fn ($d) => now()->subDays($d)->toDateString())
                ->map(fn ($d) => ['date' => $d, 'cost' => (float) ($byDay[$d] ?? 0)])->values(),
            'byJob' => DB::table('ai_runs')->where('created_at', '>=', $since)->groupBy('job')
                ->selectRaw('job, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_usd_est) as cost')
                ->orderByDesc('cost')->get(),
            'byChannel' => DB::table('ai_runs')->where('created_at', '>=', $since)->where('subject_type', 'channel')->groupBy('subject_id')
                ->selectRaw('subject_id, sum(cost_usd_est) as cost, count(*) as calls')->orderByDesc('cost')->limit(20)->get()
                ->map(fn (object $row) => [
                    'channel' => Channel::find((int) $row->subject_id)?->username,
                    'id' => (int) $row->subject_id,
                    'cost' => round((float) $row->cost, 4),
                    'calls' => (int) $row->calls,
                ]),
            'total30' => round((float) AiRun::where('created_at', '>=', $since)->sum('cost_usd_est'), 4),
            'quota' => $this->quotaPause(),
        ]);
    }

    /**
     * Channels waiting for the provider's quota (e.g. Groq tokens per day).
     *
     * @return array<string, mixed>|null
     */
    private function quotaPause(): ?array
    {
        $paused = (array) Cache::get(ProcessChannel::PAUSED_KEY, []);
        $pause = Cache::get(ProcessChannel::PAUSE_KEY);

        if ($paused === [] && ! is_array($pause)) {
            return null;
        }

        $names = Channel::whereIn('id', array_keys($paused))->pluck('username', 'id');

        return [
            'until' => is_array($pause) ? ($pause['until'] ?? null) : null,
            'reason' => is_array($pause) ? ($pause['reason'] ?? null) : null,
            'channels' => collect($paused)->map(fn (array $job, int $id) => [
                'id' => $id,
                'username' => $names[$id] ?? null,
                'step' => (string) $job['step'],
                'until' => (string) $job['until'],
            ])->values()->all(),
        ];
    }

    /**
     * Retry paused channels now (after topping up the provider plan, or to
     * test a fallback model). Each resumes from the step it stopped at.
     */
    public function resumeQuota(Request $request): RedirectResponse
    {
        $ids = array_keys((array) Cache::get(ProcessChannel::PAUSED_KEY, []));
        $resumed = collect($ids)->filter(fn (int $id) => ProcessChannel::resumeNow($id))->count();
        Cache::forget(ProcessChannel::PAUSE_KEY);
        AuditLog::record('ai.quota_resumed', null, ['channels' => $resumed], $request->user());

        return $this->done($resumed === 1 ? 'Resumed 1 channel.' : "Resumed {$resumed} channels.");
    }

    public function updateBudget(Request $request): RedirectResponse
    {
        $data = $request->validate(['budget' => ['required', 'numeric', 'min:0', 'max:10000']]);
        Setting::write(Setting::AI_DAILY_BUDGET_USD, (float) $data['budget']);
        AuditLog::record('ai.budget_changed', null, ['budget' => (float) $data['budget']], $request->user());

        return $this->done('Budget updated.');
    }

    public function toggleAi(Request $request): RedirectResponse
    {
        $paused = ! Setting::flag(Setting::AI_PAUSED);
        Setting::write(Setting::AI_PAUSED, $paused);
        AuditLog::record($paused ? 'ai.paused' : 'ai.resumed', null, [], $request->user());

        return $this->done($paused ? 'AI jobs paused.' : 'AI jobs resumed.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
