<?php

namespace App\Services\Ai;

use App\Ai\Agents\MonthDigester;
use App\Ai\Agents\ProfileWriter;
use App\Enums\SummaryMode;
use App\Enums\SummaryStatus;
use App\Enums\TriggeredBy;
use App\Models\Channel;
use App\Models\Post;
use App\Models\Summary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Step 4 (plan §8.5): map each month to a digest, then reduce digests + stats
 * + projects + top posts (+ the creator's own fields when claimed) into the
 * profile JSON from §8.6.
 */
class SummaryGenerator
{
    private const QUARTER_MERGE_THRESHOLD = 36;

    /** Stats that are for charts, not for the model (noise and wasted tokens). */
    public const NOT_FOR_AI = ['top_5_posts', 'top_post', 'weekly_activity'];

    /**
     * What each stat measures, so a fun fact can't turn "per month" into
     * "per week" while still matching the number.
     */
    private const STAT_UNITS = [
        'total_posts' => 'posts collected so far (not necessarily every post since the channel began)',
        'posts_per_week' => 'average posts per week over the last 90 days',
        'longest_streak_weeks' => 'longest run of consecutive weeks with at least one post',
        'current_streak_weeks' => 'current run of consecutive weeks with a post',
        'peak_hour' => 'busiest posting hour, 0-23, channel local time',
        'night_owl_pct' => 'percent of posts between midnight and 5am',
        'early_bird_pct' => 'percent of posts between 5am and 8am',
        'avg_post_length' => 'average characters per text post',
        'ship_rate' => 'milestone posts per MONTH (not per week)',
        'milestones' => 'number of posts that mark a milestone',
        'projects_count' => 'projects mentioned',
        'projects_shipped' => 'projects with launched status',
        'forward_ratio' => 'percent of posts that are forwards',
        'language_mix' => 'percent of text posts per language',
    ];

    public function __construct(
        private readonly AiRunner $runner,
        private readonly ContentSelector $selector,
        private readonly Prompts $prompts,
        private readonly AiLanes $lanes,
    ) {}

    public function start(Channel $channel, TriggeredBy $triggeredBy = TriggeredBy::System): Summary
    {
        $channel->loadMissing('creator');
        $mode = $channel->isClaimed() && $channel->creator !== null ? SummaryMode::Claimed : SummaryMode::Unclaimed;

        return Summary::create([
            'channel_id' => $channel->id,
            'creator_id' => $mode === SummaryMode::Claimed ? $channel->creator_id : null,
            'version' => (int) Summary::where('channel_id', $channel->id)->max('version') + 1,
            'mode' => $mode,
            'status' => SummaryStatus::Generating,
            'model' => $this->lanes->all()[0]->model('synthesis'),
            'prompt_version' => $this->prompts->version(),
            'triggered_by' => $triggeredBy,
            'month_digests' => [],
        ]);
    }

    /**
     * Digest up to $max months that are not digested yet. Returns true while more remain.
     */
    public function mapMonths(Summary $summary, ?int $max = null): bool
    {
        $max ??= (int) config('buildr.ai.months_per_job');
        $channel = $summary->channel;
        $digests = collect($summary->month_digests ?? [])->keyBy('month');
        $months = $this->postsByMonth($channel);

        // Small token budgets digest long histories per quarter ("2025-Q1").
        // Decided once per summary, so lanes with different budgets agree.
        $quarterly = $digests->isEmpty()
            ? ($cap = ($this->lanes->pick('digest', advance: false) ?? $this->lanes->all()[0])->int('max_digests')) > 0 && $months->count() > $cap
            : $digests->keys()->contains(fn (int|string $m) => str_contains((string) $m, 'Q'));

        if ($quarterly) {
            $months = $months
                ->groupBy(fn (Collection $posts, string $month) => substr($month, 0, 4).'-Q'.(int) ceil(((int) substr($month, 5, 2)) / 3), preserveKeys: true)
                ->map(fn (Collection $group) => $group->flatten(1))
                ->sortKeys();
        }

        // Keys are "Y-m" strings; map() normalizes the key type for the closures below.
        $todo = $months->keys()->map(fn (int|string $m) => (string) $m)->reject(fn (string $m) => $digests->has($m))->values();

        foreach ($todo->take($max) as $month) {
            $digests->put($month, $this->digestMonth($channel, $month, $months[$month]));

            $summary->forceFill(['month_digests' => $digests->sortKeys()->values()->all()])->save();
        }

        return $todo->count() > $max;
    }

    /**
     * Write the final profile JSON into the summary (status → draft).
     */
    public function reduce(Summary $summary): Summary
    {
        $channel = $summary->channel()->with(['creator.socials', 'creator.tags', 'stats'])->firstOrFail();

        // A retry after failed verification carries the checker's reasons.
        $feedback = (array) ($summary->verification['feedback'] ?? []);
        $retryNote = $feedback === [] ? '' : "\n\nA previous draft was rejected by the fact-checker. Do not repeat these problems:\n- ".implode("\n- ", $feedback);

        // The writer's input is sized for the lane it lands on.
        $data = $this->runner->run('writer', 'generate_summary', fn (AiLane $lane, string $model) => [
            new ProfileWriter($model, $summary->mode),
            $this->prompts->message(
                (string) json_encode($this->writerInput($channel, $summary), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'input',
                "Write the profile for @{$channel->username}.{$retryNote}\n\nInput:",
                "Write the profile for @{$channel->username} using only the input above. Before answering, check that every msg_id you cite is in the input and every quote is copied exactly.{$retryNote}",
            ),
        ], $summary);

        $model = $this->runner->lastModel() ?? $this->lanes->all()[0]->model('synthesis');

        [$content, $citations] = $this->normalizeContent($data, $summary->mode);

        $summary->forceFill([
            'content' => $content,
            'citations' => $citations,
            'status' => SummaryStatus::Draft,
            'model' => $model,
        ])->save();

        return $summary;
    }

    /**
     * Regenerate a single section by running the writer again and keeping only
     * that section from the new output (creator "regenerate" button).
     */
    public function regenerateSection(Summary $base, string $section, TriggeredBy $by): Summary
    {
        $summary = Summary::create([
            'channel_id' => $base->channel_id,
            'creator_id' => $base->creator_id,
            'version' => (int) Summary::where('channel_id', $base->channel_id)->max('version') + 1,
            'mode' => $base->mode,
            'status' => SummaryStatus::Generating,
            'model' => $this->lanes->all()[0]->model('synthesis'),
            'prompt_version' => $this->prompts->version(),
            'triggered_by' => $by,
            'month_digests' => $base->month_digests,
        ]);

        $fresh = $this->reduce($summary);
        $content = $base->content ?? [];
        $citations = $base->citations ?? [];

        $content[$section] = $fresh->content[$section] ?? null;

        if (in_array($section, ['hook', 'intro'], true)) {
            $citations[$section] = $fresh->citations[$section] ?? [];
        }

        $summary->forceFill(['content' => $content, 'citations' => $citations])->save();

        return $summary;
    }

    /**
     * Eligible, non-sensitive posts grouped by month ("Y-m" keys, oldest first).
     *
     * @return Collection<array-key, Collection<int, Post>>
     */
    public function postsByMonth(Channel $channel): Collection
    {
        return $this->selector->eligible($channel)
            ->load('extraction')
            ->reject(fn (Post $p) => $p->extraction?->is_personal_sensitive === true)
            ->filter(fn (Post $p) => $p->posted_at !== null)
            ->toBase()
            ->groupBy(fn (Post $p) => $p->posted_at->setTimezone($channel->effectiveTimezone())->format('Y-m'))
            ->sortKeys();
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{month: string, summary: string, cites: list<int>}
     */
    private function digestMonth(Channel $channel, string $month, Collection $posts): array
    {
        $data = $this->runner->run('digest', 'month_digest', fn (AiLane $lane, string $model) => [
            new MonthDigester($model),
            $this->prompts->message(
                json_encode($this->digestPayload($channel, $month, $posts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'month',
                '',
                "Write the digest of {$month} for @{$channel->username} from the posts above.",
            ),
        ], $channel);

        $valid = $posts->pluck('msg_id')->flip();

        return [
            'month' => $month,
            'summary' => Str::limit(trim((string) ($data['summary'] ?? '')), 1200),
            'cites' => array_values(collect((array) ($data['cites'] ?? []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $valid->has($id))
                ->unique()->take(8)->all()),
        ];
    }

    /**
     * One period's posts for the digester, sized by the active lane's budget.
     *
     * @param  Collection<int, Post>  $posts
     * @return array<string, mixed>
     */
    private function digestPayload(Channel $channel, string $month, Collection $posts): array
    {
        // Busy months keep the posts that carry the story: milestones, project
        // mentions, then the most viewed.
        $selected = $posts->sortByDesc(fn (Post $p) => [
            filled($p->extraction?->milestone),
            filled($p->extraction?->projects),
            $p->views ?? 0,
        ])->take(AiBudgets::int('digest_max_posts'))->sortBy('msg_id');

        $labeled = $selected->map(fn (Post $p) => $this->withoutEmpty([
            'msg_id' => $p->msg_id,
            'date' => $p->posted_at?->toDateString(),
            'type' => $p->extraction?->type?->value,
            'topics' => $p->extraction?->topics,
            'projects' => $p->extraction?->projects,
            'milestone' => $p->extraction?->milestone,
            'is_forward' => $p->is_forward,
            'is_ad' => $p->is_ad,
            'snippet' => Str::limit((string) $p->text, AiBudgets::int('digest_snippet_chars')),
        ]))->values()->all();

        $top = $posts->sortByDesc('views')->take(AiBudgets::int('digest_top_posts'))->map(fn (Post $p) => [
            'msg_id' => $p->msg_id,
            'views' => $p->views,
            'text' => Str::limit((string) $p->text, AiBudgets::int('digest_top_chars')),
        ])->values()->all();

        $payload = ['channel' => '@'.$channel->username, 'month' => $month, 'posts' => $labeled, 'top_posts' => $top];

        // Small budgets: one line per post instead of repeating JSON keys (~40% fewer tokens).
        if (AiBudgets::int('compact_lines') === 1) {
            $payload = [
                'channel' => '@'.$channel->username,
                'month' => $month,
                'posts_format' => 'msg_id | date | type | topics | projects | milestone | snippet',
                'posts' => array_map(fn (array $r) => self::line([
                    $r['msg_id'],
                    $r['date'] ?? '',
                    $r['type'] ?? '',
                    implode(', ', (array) ($r['topics'] ?? [])),
                    collect((array) ($r['projects'] ?? []))->map(fn ($pr) => is_array($pr) ? ($pr['name'] ?? '').' ('.($pr['role'] ?? '').')' : '')->filter()->implode('; '),
                    $r['milestone'] ?? '',
                    $r['snippet'] ?? '',
                ]), $labeled),
                'top_posts_format' => 'msg_id | views | text',
                'top_posts' => array_map(fn (array $r) => self::line([$r['msg_id'], $r['views'] ?? '', $r['text']]), $top),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function writerInput(Channel $channel, Summary $summary): array
    {
        $months = collect($summary->month_digests ?? []);
        $threshold = min(self::QUARTER_MERGE_THRESHOLD, AiBudgets::int('writer_max_digests'));

        // Long histories collapse to quarters, then to years if still too many.
        $digests = $months;

        foreach ([fn (int $y, int $m) => $y.'-Q'.(int) ceil($m / 3), fn (int $y) => (string) $y] as $period) {
            if ($digests->count() <= $threshold) {
                break;
            }

            $digests = $months->groupBy(function (array $d) use ($period) {
                [$year, $month] = explode('-', $d['month']);
                // Quarterly digests ("2025-Q2") count from the quarter's first month.
                $month = str_starts_with($month, 'Q') ? ((int) substr($month, 1) - 1) * 3 + 1 : (int) $month;

                return $period((int) $year, $month);
            })->map(fn (Collection $group, string $label) => [
                'month' => $label,
                'summary' => $group->pluck('summary')->implode(' '),
                'cites' => $group->pluck('cites')->flatten()->take(10)->values()->all(),
            ])->values();
        }

        $chars = AiBudgets::int('writer_digest_chars');
        /** @var Collection<int, array<string, mixed>> $digests */
        $digests = $digests->map(fn (array $d) => [...$d, 'summary' => Str::limit((string) $d['summary'], $chars)])->values();

        $stats = $channel->stats->stats ?? [];

        $creator = null;

        if ($summary->mode === SummaryMode::Claimed && $channel->creator !== null) {
            $creator = [
                'display_name' => $channel->creator->display_name,
                'real_name' => $channel->creator->real_name,
                'real_name_am' => $channel->creator->real_name_am,
                'bio' => $channel->creator->bio,
                'location' => $channel->creator->location,
                'tags' => $channel->creator->tags->pluck('name')->all(),
                'socials' => $channel->creator->socials->pluck('platform')->map->value->all(),
            ];
        }

        return [
            'mode' => $summary->mode->value,
            'channel' => [
                'username' => '@'.$channel->username,
                'title' => $channel->title,
                'description' => $channel->description,
                'subscribers' => $channel->subscribers,
            ],
            'creator' => $creator,
            'stats' => collect($stats)->except(self::NOT_FOR_AI)->all(),
            'stat_units' => self::STAT_UNITS,
            'allowed_fun_fact_sources' => $this->statKeys($stats),
            'projects' => $channel->projects()->visible()->orderByDesc('mention_count')->limit(15)->get()->map(fn ($p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'status' => $p->status->value,
                'description' => $p->description,
                'history' => $p->history,
                'mentions' => $p->mention_count,
                'first_seen' => $p->first_seen_at?->format('Y-m'),
                'last_seen' => $p->last_seen_at?->format('Y-m'),
            ])->values()->all(),
            'month_digests' => $digests->values()->all(),
            ...$this->writerPostsPayload($channel, $digests),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $digests
     * @return array<string, mixed>
     */
    private function writerPostsPayload(Channel $channel, Collection $digests): array
    {
        $posts = $this->writerPosts($channel, $digests);

        if (AiBudgets::int('compact_lines') !== 1) {
            return ['posts' => $posts];
        }

        return [
            'posts_format' => 'msg_id | date | views | type | text',
            'posts' => array_map(fn (array $p) => self::line([$p['msg_id'], $p['date'] ?? '', $p['views'] ?? '', $p['type'] ?? '', $p['text']]), $posts),
        ];
    }

    /**
     * Posts the writer may cite or quote: top posts, recent posts, and posts
     * the digests cite, with full text.
     *
     * @param  Collection<int, array<string, mixed>>  $digests
     * @return list<array<string, mixed>>
     */
    private function writerPosts(Channel $channel, Collection $digests): array
    {
        $eligible = $this->selector->eligible($channel)
            ->load('extraction')
            ->reject(fn (Post $p) => $p->extraction?->is_personal_sensitive === true)
            ->keyBy('msg_id');

        $cited = $digests->pluck('cites')->flatten()->map(fn ($id) => (int) $id);
        $max = AiBudgets::int('writer_max_posts');
        $ids = $eligible->sortByDesc('views')->take((int) ceil($max / 4))->keys()
            ->merge($eligible->sortByDesc('msg_id')->take((int) ceil($max / 6))->keys())
            ->merge($cited)
            ->unique()
            ->take($max);

        return array_values($ids->map(fn ($id) => $eligible->get($id))
            ->filter()
            ->sortBy('msg_id')
            ->map(fn (Post $p) => [
                'msg_id' => $p->msg_id,
                'date' => $p->posted_at?->toDateString(),
                'views' => $p->views,
                'type' => $p->extraction?->type?->value,
                'text' => Str::limit((string) $p->text, AiBudgets::int('writer_post_chars')),
            ])
            ->all());
    }

    /**
     * One compact, pipe-separated line; pipes and newlines inside values are flattened.
     *
     * @param  list<int|string|null>  $cells
     */
    public static function line(array $cells): string
    {
        return implode(' | ', array_map(
            fn ($c) => trim((string) preg_replace('/\s*\|\s*|\s+/u', ' ', (string) $c)),
            $cells,
        ));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return list<string>
     */
    private function statKeys(array $stats): array
    {
        $keys = [];

        foreach ($stats as $key => $value) {
            if (in_array($key, self::NOT_FOR_AI, true) || $value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    $keys[] = "stats.{$key}.{$sub}";
                }

                continue;
            }

            $keys[] = "stats.{$key}";
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, list<int>>}
     */
    public function normalizeContent(array $data, SummaryMode $mode): array
    {
        $ints = fn ($v): array => array_values(collect((array) $v)->map(fn ($id) => (int) $id)->filter()->unique()->all());

        $content = [
            'hook' => trim((string) ($data['hook'] ?? '')),
            'vibe_tags' => collect((array) ($data['vibe_tags'] ?? []))->map(fn ($t) => Str::limit(trim((string) $t), 24, ''))->filter()->take(3)->values()->all(),
            'intro' => trim((string) ($data['intro'] ?? '')),
            'building_now' => collect((array) ($data['building_now'] ?? []))->map(fn ($i) => [
                'project_slug' => (string) ($i['project_slug'] ?? ''),
                'line' => trim((string) ($i['line'] ?? '')),
                'cites' => $ints($i['cites'] ?? []),
            ])->take(5)->values()->all(),
            'eras' => collect((array) ($data['eras'] ?? []))->map(fn ($i) => [
                'name' => trim((string) ($i['name'] ?? '')),
                'from' => (string) ($i['from'] ?? ''),
                'to' => (string) ($i['to'] ?? ''),
                'story' => trim((string) ($i['story'] ?? '')),
                'cites' => $ints($i['cites'] ?? []),
            ])->take(5)->values()->all(),
            'start_here' => collect((array) ($data['start_here'] ?? []))->map(fn ($i) => [
                'msg_id' => (int) ($i['msg_id'] ?? 0),
                'why' => trim((string) ($i['why'] ?? '')),
            ])->take(5)->values()->all(),
            'in_their_words' => collect((array) ($data['in_their_words'] ?? []))->map(fn ($i) => [
                'msg_id' => (int) ($i['msg_id'] ?? 0),
                'quote' => trim((string) ($i['quote'] ?? '')),
            ])->take(2)->values()->all(),
            'how_they_work' => $mode === SummaryMode::Claimed && is_array($data['how_they_work'] ?? null) && filled($data['how_they_work']['text'] ?? null)
                ? ['text' => trim((string) $data['how_they_work']['text']), 'cites' => $ints($data['how_they_work']['cites'] ?? [])]
                : null,
            'fun_facts' => collect((array) ($data['fun_facts'] ?? []))->map(fn ($i) => [
                'text' => trim((string) ($i['text'] ?? '')),
                'source' => (string) ($i['source'] ?? ''),
            ])->take(4)->values()->all(),
        ];

        $citations = [
            'hook' => $ints($data['hook_cites'] ?? []),
            'intro' => $ints($data['intro_cites'] ?? []),
        ];

        return [$content, $citations];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $row): array
    {
        return array_filter($row, fn ($v) => $v !== null && $v !== [] && $v !== false && $v !== '');
    }
}
