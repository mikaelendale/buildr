<?php

namespace App\Services\Ai;

use App\Ai\Agents\ProjectDescriber;
use App\Ai\Agents\ProjectResolver;
use App\Enums\ContentSource;
use App\Enums\LinkKind;
use App\Enums\PostType;
use App\Enums\ProjectStatus;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Models\Project;
use App\Services\Ai\Data\MentionGroup;
use App\Services\Ai\Data\ProjectMention;
use App\Services\Ai\Exceptions\AiBudgetExceededException;
use App\Services\Ai\Exceptions\AiQuotaExhaustedException;
use App\Services\Ai\Exceptions\InvalidAiOutputException;
use App\Support\Similarity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Step 2 (plan §8.3, reworked after real channels).
 *
 * 1. Deterministic pre-grouping of `own` project mentions: fuzzy name match,
 *    plus brand tokens so "RAVN", "useravn.com" and "getravn.xyz" start out
 *    together.
 * 2. Context squeeze: each candidate becomes a small dossier (names, urls,
 *    dated milestones, the sentences that mention it, newest first).
 * 3. One resolver call merges renames, domain moves and pivots, drops
 *    third-party tools and noise, and writes status/url/description/history
 *    from the latest evidence.
 *
 * If the resolver fails, the old behaviour (one project per group, then a
 * describer call) keeps the pipeline moving. Creator-added or edited
 * projects and hidden ones are never overwritten or removed.
 */
class ProjectBuilder
{
    private const MERGE_SIMILARITY = 0.8;

    /** Post intents that show the author building something. */
    private const BUILDING = [PostType::ProjectLaunch, PostType::ProgressUpdate];

    /** Post intents that are about other people's things. */
    private const NOT_BUILDING = [PostType::ResourceShare, PostType::Promo];

    private const LAUNCH_WORDS = ['launch', 'launched', 'shipped', 'released', 'live', 'v1', '1.0', 'public', 'open-sourced', 'open sourced', 'published'];

    public function __construct(
        private readonly AiRunner $runner,
        private readonly Prompts $prompts,
    ) {}

    /**
     * @return EloquentCollection<int, Project>
     */
    public function build(Channel $channel): EloquentCollection
    {
        $groups = array_values(array_filter($this->group($this->collectMentions($channel)), fn (MentionGroup $g) => self::isOwnWork($g)));

        if ($groups !== []) {
            try {
                $this->resolve($channel, $groups);
            } catch (AiBudgetExceededException|AiQuotaExhaustedException|RateLimitedException $e) {
                throw $e; // the job pauses and resumes; don't burn the fallback call
            } catch (Throwable $e) {
                Log::channel('buildr')->warning("Project resolver failed for @{$channel->username}: {$e->getMessage()}");

                // Only a channel with no projects yet gets the plain per-name fallback;
                // otherwise keep what the last good run produced.
                if (! $channel->projects()->exists()) {
                    $this->buildWithoutResolver($channel, $groups);
                }
            }
        } else {
            // No candidate survives the guards: AI-made rows have no evidence left.
            $channel->projects()->where('source', ContentSource::Ai)->where('hidden', false)->delete();
        }

        return $channel->projects()->get();
    }

    public static function normalize(string $name): string
    {
        $name = Str::lower(Str::ascii($name)) ?: mb_strtolower($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\b(app|bot|the|project)\b/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * Brand token: a domain becomes its main label without marketing
     * prefixes ("useravn.com" → "ravn"), keeping a subdomain as its own
     * thing ("loan.example.site" → "loan example").
     */
    public static function brand(string $name): string
    {
        $value = mb_strtolower(trim($name));

        if (preg_match('/^(?:https?:\/\/)?(?:www\.)?((?:[a-z0-9-]+\.)+[a-z]{2,})(?:\/|$)/', $value, $m) === 1) {
            $labels = explode('.', $m[1]);
            $main = $labels[count($labels) - 2];
            $main = preg_replace('/^(use|get|try|join|go|my)(?=[a-z0-9]{3,})/', '', $main) ?? $main;
            $value = count($labels) > 2 ? $labels[0].' '.$main : $main;
        }

        return self::normalize($value);
    }

    public static function similarity(string $a, string $b): float
    {
        return Similarity::trigram($a, $b);
    }

    /**
     * @return list<ProjectMention>
     */
    private function collectMentions(Channel $channel): array
    {
        $extractions = PostExtraction::query()
            ->whereIn('post_id', Post::select('id')->where('channel_id', $channel->id)->where('is_deleted', false))
            ->where('is_personal_sensitive', false)
            ->with('post.links')
            ->get();

        $mentions = [];

        foreach ($extractions as $extraction) {
            // Forwards are someone else's words; shares and promos point at other people's things.
            if ($extraction->post === null || $extraction->post->is_forward || in_array($extraction->type, self::NOT_BUILDING, true)) {
                continue;
            }

            $extraction->post->setRelation('extraction', $extraction);

            foreach ($extraction->projects ?? [] as $project) {
                if (! is_array($project) || ($project['role'] ?? null) !== 'own' || ! is_string($project['name'] ?? null)) {
                    continue;
                }

                $normalized = self::normalize($project['name']);

                if ($normalized === '') {
                    continue;
                }

                $mentions[] = new ProjectMention(trim($project['name']), $normalized, $extraction->post, $extraction->milestone);
            }
        }

        return $mentions;
    }

    /**
     * Guards against labels the model got wrong, before any AI call:
     * well-known third-party names ("Opus 4.8", "Cursor") are never the
     * author's, and a project needs building intent (a launch, a progress
     * update or a milestone). Apps the author only tried or reviewed come
     * from opinion and share posts, which never qualify on their own; the
     * resolver sees each excerpt's intent for the rest.
     */
    public static function isOwnWork(MentionGroup $group): bool
    {
        $thirdParty = array_map('mb_strtolower', (array) config('buildr.ai.third_party_names', []));

        // The bare name, or the name plus a version ("Opus 4.8", "GPT-5"); "Chapa Bot" is fine.
        $pattern = '/^(?:'.implode('|', array_map(fn (string $t) => preg_quote($t, '/'), $thirdParty)).')(?:[\s-]*v?\d[\w.]*)?$/u';

        foreach ($group->names as $name) {
            if ($thirdParty !== [] && preg_match($pattern, mb_strtolower(trim($name))) === 1) {
                return false;
            }
        }

        $posts = collect($group->posts);

        return $posts->contains(fn (Post $p) => in_array($p->extraction?->type, self::BUILDING, true) || filled($p->extraction?->milestone));
    }

    /**
     * Merge mentions with the same brand token or trigram-similar names (> 0.8).
     *
     * @param  list<ProjectMention>  $mentions
     * @return list<MentionGroup>
     */
    private function group(array $mentions): array
    {
        $groups = [];

        foreach ($mentions as $mention) {
            $brand = self::brand($mention->name);
            $target = null;

            foreach ($groups as $group) {
                if (($brand !== '' && in_array($brand, $group->brands, true))
                    || self::similarity($group->normalized, $mention->normalized) > self::MERGE_SIMILARITY) {
                    $target = $group;
                    break;
                }
            }

            if ($target === null) {
                $target = new MentionGroup($mention->normalized);
                $groups[] = $target;
            }

            $target->add($mention, $brand);
        }

        return $groups;
    }

    /**
     * @param  list<MentionGroup>  $groups
     */
    private function resolve(Channel $channel, array $groups): void
    {
        /** @var Collection<string, MentionGroup> $keyed */
        $keyed = collect();

        // The candidate list and excerpt sizes follow the lane the call lands on.
        $data = $this->runner->run('resolve', 'resolve_projects', function (AiLane $lane, string $model) use ($channel, $groups, &$keyed) {
            $ranked = collect($groups)
                ->sortByDesc(fn (MentionGroup $g) => [$g->postsByDate()->last()?->posted_at?->getTimestamp() ?? 0, count($g->posts)])
                ->take(AiBudgets::int('resolver_max_candidates') ?: 60)
                ->values();

            $keyed = $ranked->mapWithKeys(fn (MentionGroup $g, int $i) => ['c'.($i + 1) => $g]);
            $dossiers = $keyed->map(fn (MentionGroup $g, string $key) => $this->dossier($key, $g))->values()->all();

            return [new ProjectResolver($model), $this->prompts->message(
                (string) json_encode(['candidates' => $dossiers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'candidates',
                "Resolve the projects of @{$channel->username}.",
                "Resolve the projects of @{$channel->username} from the candidates above. Every candidate key goes in exactly one project's members or in dropped.",
            )];
        }, $channel);

        $claimed = [];
        $resolved = [];

        // Models sometimes answer with a candidate's name instead of its key.
        $byName = $keyed->flatMap(fn (MentionGroup $g, string $key) => collect($g->names)
            ->mapWithKeys(fn (string $n) => [mb_strtolower(trim($n)) => $key]));
        $toKey = fn ($m): ?string => $keyed->has((string) $m) ? (string) $m : ($byName[mb_strtolower(trim((string) $m))] ?? null);

        foreach ((array) ($data['projects'] ?? []) as $row) {
            $members = collect((array) ($row['members'] ?? []))
                ->map($toKey)
                ->filter(fn (?string $k) => $k !== null && ! isset($claimed[$k]))
                ->unique()->values();

            if ($members->isEmpty() || blank($row['name'] ?? null)) {
                continue;
            }

            $members->each(function (string $k) use (&$claimed) {
                $claimed[$k] = true;
            });

            $resolved[] = ['row' => $row, 'groups' => array_values($members->map(fn (string $k) => $keyed->get($k))->filter()->all())];
        }

        $droppedKeys = collect((array) ($data['dropped'] ?? []))
            ->map(fn ($d) => is_array($d) ? $toKey($d['key'] ?? '') : null)
            ->filter()->unique()->values();
        $dropped = collect((array) ($data['dropped'] ?? []))
            ->map(fn ($d) => is_array($d) ? ($d['key'] ?? '?').' ('.Str::limit((string) ($d['reason'] ?? ''), 60).')' : '')
            ->filter()->implode(', ');

        Log::channel('buildr')->info(sprintf(
            'Projects for @%s: %d candidates → %d projects. Dropped: %s',
            $channel->username, $keyed->count(), count($resolved), $dropped ?: 'none',
        ));

        // A reply that keeps nothing (unknown keys, empty list) is a bad answer,
        // not a verdict: leave the current projects exactly as they are.
        if ($resolved === []) {
            throw new InvalidAiOutputException('Project resolver returned no usable projects.');
        }

        // Candidates the reply actually decided on (merged into a project or dropped).
        $decided = collect(array_keys($claimed))->merge($droppedKeys)->unique();

        DB::transaction(function () use ($channel, $resolved, $keyed, $decided) {
            $keep = [];

            foreach ($resolved as $item) {
                $keep[] = $this->upsertResolved($channel, $item['row'], $item['groups'])->id;
            }

            // Remove AI rows the resolver merged away or dropped, and rows no
            // candidate covers any more. A row whose candidate the reply simply
            // skipped stays. Hidden and creator rows are never touched.
            $channel->projects()
                ->where('source', ContentSource::Ai)
                ->where('hidden', false)
                ->whereNotIn('id', $keep)
                ->get()
                ->each(function (Project $project) use ($keyed, $decided) {
                    $key = $keyed->search(fn (MentionGroup $g) => $g->normalized === $project->normalized_name
                        || in_array(self::brand($project->name), $g->brands, true)
                        || self::similarity($g->normalized, $project->normalized_name) > self::MERGE_SIMILARITY);

                    if ($key === false || $decided->contains($key)) {
                        $project->delete();
                    }
                });
        });
    }

    /**
     * A candidate squeezed to what the resolver needs: names, urls, dated
     * milestones and only the sentences that mention it, newest first.
     *
     * @return array<string, mixed>
     */
    private function dossier(string $key, MentionGroup $group): array
    {
        $posts = $group->postsByDate();
        $names = array_values(array_unique($group->names));
        $snippets = max(1, AiBudgets::int('resolver_snippets') ?: 4);
        $chars = max(80, AiBudgets::int('resolver_snippet_chars') ?: 220);

        // Newest posts carry the current state; the oldest one shows where it started.
        $evidence = $posts->reverse()->take($snippets)->values();

        if ($posts->count() > $snippets && $posts->first() !== null) {
            $evidence = $evidence->slice(0, $snippets - 1)->push($posts->first());
        }

        return array_filter([
            'key' => $key,
            'names' => array_slice($names, 0, 4),
            'urls' => $this->candidateUrls($group),
            'mentions' => $posts->count(),
            'first' => $posts->first()?->posted_at?->format('Y-m'),
            'last' => $posts->last()?->posted_at?->format('Y-m'),
            'milestones' => array_slice($group->timeline, -3),
            // The post's intent rides along ("launch", "opinion"…), so the resolver can tell building from trying.
            'excerpts' => $evidence->map(fn (Post $p) => ($p->posted_at?->toDateString() ?? '?').' ['.($p->extraction?->type->value ?? 'other').']: '.$this->excerpt($p, $names, $chars))->values()->all(),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * The sentence(s) that mention the project, not the whole post.
     *
     * @param  list<string>  $names
     */
    private function excerpt(Post $post, array $names, int $chars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $post->text));
        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text];
        $needles = collect($names)
            ->flatMap(fn (string $n) => [mb_strtolower($n), self::brand($n)])
            ->filter(fn (string $n) => mb_strlen($n) >= 3)
            ->unique();

        foreach ($sentences as $i => $sentence) {
            $lower = mb_strtolower($sentence);

            if ($needles->contains(fn (string $n) => str_contains($lower, $n))) {
                $hit = $sentence;

                if (mb_strlen($hit) < 70 && isset($sentences[$i + 1])) {
                    $hit .= ' '.$sentences[$i + 1];
                }

                return Str::limit($hit, $chars);
            }
        }

        return Str::limit($sentences[0], $chars);
    }

    /**
     * Links from the project's posts that belong to it (same brand), plus
     * domains it was named by. The resolver may only pick from these.
     *
     * @return list<string>
     */
    private function candidateUrls(MentionGroup $group): array
    {
        $urls = collect($group->names)
            ->filter(fn (string $n) => preg_match('/^(?:https?:\/\/)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/\S*)?$/i', $n) === 1)
            ->map(fn (string $n) => str_starts_with($n, 'http') ? $n : 'https://'.mb_strtolower($n));

        $links = collect($group->posts)
            ->flatMap(fn (Post $p) => $p->links)
            ->reject(fn ($link) => in_array($link->kind, [LinkKind::Telegram, LinkKind::X, LinkKind::Youtube], true))
            ->map(fn ($link) => (string) $link->url)
            ->filter(fn (string $url) => (string) parse_url($url, PHP_URL_HOST) !== '');

        // Links on the project's own domain first, then other product links
        // from the same posts (a move to a new address often lives there).
        [$own, $other] = $links->partition(function (string $url) use ($group) {
            $host = str_replace(['-', '.'], '', (string) parse_url($url, PHP_URL_HOST));

            return collect($group->brands)->contains(fn (string $b) => $b !== '' && str_contains($host, str_replace(' ', '', $b)));
        });

        return array_values($urls->merge($own)->merge($other)->unique()->take(5)->all());
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<MentionGroup>  $groups
     */
    private function upsertResolved(Channel $channel, array $row, array $groups): Project
    {
        $posts = collect($groups)->flatMap(fn (MentionGroup $g) => $g->postsByDate())->unique('id')->sortBy('posted_at')->values();
        $name = Str::limit(trim((string) $row['name']), 80, '');
        $normalized = self::normalize($name) ?: $groups[0]->normalized;
        $memberNorms = collect($groups)->pluck('normalized')->push($normalized)->unique();

        $allowedUrls = collect($groups)->flatMap(fn (MentionGroup $g) => $this->candidateUrls($g))->unique();
        $url = in_array($row['url'] ?? null, $allowedUrls->all(), true) ? (string) $row['url'] : null;

        $status = ProjectStatus::tryFrom((string) ($row['status'] ?? '')) ?? ProjectStatus::Unknown;
        $aliases = collect($groups)->flatMap(fn (MentionGroup $g) => $g->names)
            ->map(fn (string $n) => trim($n))
            ->reject(fn (string $n) => self::normalize($n) === $normalized)
            ->unique(fn (string $n) => mb_strtolower($n))
            ->take(8)->values()->all();

        $existing = $channel->projects()->get()
            ->filter(fn (Project $p) => $memberNorms->contains($p->normalized_name)
                || $memberNorms->contains(fn (string $n) => self::similarity($p->normalized_name, $n) > self::MERGE_SIMILARITY))
            ->sortBy(fn (Project $p) => [$p->source === ContentSource::Creator ? 0 : 1, $p->hidden ? 1 : 0, $p->id])
            ->first();

        $mentionData = [
            'first_seen_post_id' => $posts->first()?->id,
            'last_seen_post_id' => $posts->last()?->id,
            'first_seen_at' => $posts->first()?->posted_at,
            'last_seen_at' => $posts->last()?->posted_at,
            'mention_count' => $posts->count(),
            'aliases' => $aliases ?: null,
        ];

        $content = [
            'name' => $name,
            'normalized_name' => $normalized,
            'url' => $url ?? $this->pickUrl($posts, $name),
            'status' => $status,
            'description' => filled($row['description'] ?? null) ? Str::limit(trim((string) $row['description']), 300) : null,
            'history' => filled($row['history'] ?? null) ? Str::limit(trim((string) $row['history']), 300) : null,
        ];

        if ($existing === null) {
            return $channel->projects()->create($mentionData + $content + [
                'creator_id' => $channel->creator_id,
                'slug' => $this->uniqueSlug($channel, $name),
                'source' => ContentSource::Ai,
            ]);
        }

        // Creator-owned rows keep their content; we only refresh mention data.
        $existing->update($existing->source === ContentSource::Ai ? $mentionData + $content : $mentionData);

        return $existing;
    }

    /**
     * Fallback when the resolver is unavailable: one project per group.
     *
     * @param  list<MentionGroup>  $groups
     */
    private function buildWithoutResolver(Channel $channel, array $groups): void
    {
        $touched = collect();
        $postsByProject = [];

        foreach ($groups as $group) {
            $project = $this->upsert($channel, $group);
            $touched->push($project);
            $postsByProject[$project->id] = $group->postsByDate();
        }

        $this->describe(
            $channel,
            $touched->filter(fn (Project $p) => $p->source === ContentSource::Ai && blank($p->description)),
            $postsByProject,
        );
    }

    private function upsert(Channel $channel, MentionGroup $group): Project
    {
        $posts = $group->postsByDate();
        $name = $group->name();
        $first = $posts->first();
        $last = $posts->last();

        $project = $channel->projects()->where('normalized_name', $group->normalized)->first()
            ?? $channel->projects()->get()->first(fn (Project $p) => self::similarity($p->normalized_name, $group->normalized) > self::MERGE_SIMILARITY);

        $attributes = [
            'first_seen_post_id' => $first?->id,
            'last_seen_post_id' => $last?->id,
            'first_seen_at' => $first?->posted_at,
            'last_seen_at' => $last?->posted_at,
            'mention_count' => $posts->count(),
        ];

        if ($project === null) {
            return $channel->projects()->create($attributes + [
                'creator_id' => $channel->creator_id,
                'name' => $name,
                'slug' => $this->uniqueSlug($channel, $name),
                'normalized_name' => $group->normalized,
                'url' => $this->pickUrl($posts, $name),
                'status' => $this->status($posts, $group->milestones),
                'source' => ContentSource::Ai,
            ]);
        }

        if ($project->source === ContentSource::Ai) {
            $attributes += [
                'status' => $this->status($posts, $group->milestones),
                'url' => $project->url ?? $this->pickUrl($posts, $name),
            ];
        }

        $project->update($attributes);

        return $project;
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  list<string>  $milestones
     */
    private function status(Collection $posts, array $milestones): ProjectStatus
    {
        $launched = collect($milestones)->contains(function (string $m) {
            $m = mb_strtolower($m);

            return collect(self::LAUNCH_WORDS)->contains(fn ($w) => str_contains($m, $w));
        });

        if ($launched) {
            return ProjectStatus::Launched;
        }

        $lastSeen = $posts->max('posted_at');

        if ($lastSeen !== null && $lastSeen->lt(now()->subMonths(6))) {
            return ProjectStatus::Unknown;
        }

        return ProjectStatus::Building;
    }

    /**
     * Prefer a GitHub repo or product domain linked from the project's posts.
     *
     * @param  Collection<int, Post>  $posts
     */
    private function pickUrl(Collection $posts, string $name): ?string
    {
        $links = $posts->flatMap(fn (Post $p) => $p->links)
            ->reject(fn ($link) => in_array($link->kind, [LinkKind::Telegram, LinkKind::X, LinkKind::Youtube], true));

        $slug = Str::slug($name, '');

        $match = $links->first(fn ($link) => $slug !== '' && str_contains(str_replace(['-', '_', '.'], '', mb_strtolower($link->url)), $slug));

        return ($match ?? $links->firstWhere('kind', LinkKind::Github) ?? $links->firstWhere('kind', LinkKind::Product))?->url;
    }

    private function uniqueSlug(Channel $channel, string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $i = 2;

        while ($channel->projects()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  array<int, Collection<int, Post>>  $postsByProject
     */
    private function describe(Channel $channel, Collection $projects, array $postsByProject): void
    {
        if ($projects->isEmpty()) {
            return;
        }

        $payload = $projects->map(fn (Project $project) => [
            'key' => (string) $project->id,
            'name' => $project->name,
            'posts' => ($postsByProject[$project->id] ?? collect())
                ->sortByDesc('views')
                ->take(4)
                ->map(fn (Post $p) => Str::limit((string) $p->text, 800))
                ->values()
                ->all(),
        ])->values()->all();

        $data = $this->runner->run('describe', 'describe_projects', fn (AiLane $lane, string $model) => [
            new ProjectDescriber($model),
            $this->prompts->message(
                json_encode(['projects' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'projects',
                '',
                'Write one description for every project key above, using only its posts.',
            ),
        ], $channel);

        foreach ((array) ($data['projects'] ?? []) as $row) {
            $project = $projects->firstWhere('id', (int) ($row['key'] ?? 0));

            if ($project !== null && filled($row['description'] ?? null)) {
                $project->update(['description' => Str::limit(trim((string) $row['description']), 300)]);
            }
        }
    }
}
