<?php

namespace App\Services\Ai;

use App\Ai\Agents\PostExtractor;
use App\Enums\PostTone;
use App\Enums\PostType;
use App\Models\Channel;
use App\Models\Post;
use App\Models\PostExtraction;
use App\Services\Ai\Exceptions\InvalidAiOutputException;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Step 1 (plan §8.2). Idempotent per post + prompt_version: only posts without
 * a current extraction are sent, so re-running costs nothing.
 */
class PostExtractionService
{
    public function __construct(
        private readonly AiRunner $runner,
        private readonly ContentSelector $selector,
        private readonly Prompts $prompts,
        private readonly AiLanes $lanes,
    ) {}

    /**
     * @return Collection<int, Post>
     */
    public function pending(Channel $channel): Collection
    {
        $version = $this->prompts->version();

        $done = PostExtraction::query()
            ->whereIn('post_id', Post::select('id')->where('channel_id', $channel->id))
            ->where('prompt_version', $version)
            ->pluck('post_id')
            ->flip();

        return $this->sample($this->selector->eligible($channel))
            ->reject(fn (Post $post) => $done->has($post->id))
            ->values();
    }

    /**
     * With an `extraction_max_posts` budget, label a representative sample
     * instead of every post: the newest quarter (where projects' current
     * names and addresses live), the most viewed third, plus posts spread
     * evenly across the channel's history so every era is still covered.
     * With several lanes the most generous cap applies.
     *
     * @param  Collection<int, Post>  $posts
     * @return Collection<int, Post>
     */
    public function sample(Collection $posts): Collection
    {
        $max = $this->lanes->widest('extraction_max_posts');

        if ($max <= 0 || $posts->count() <= $max) {
            return $posts;
        }

        $newest = $posts->sortByDesc('msg_id')->take(intdiv($max, 4))->keyBy('id');
        $top = $newest->union($posts->reject(fn (Post $p) => $newest->has($p->id))->sortByDesc('views')->take(intdiv($max, 3))->keyBy('id'));
        $rest = $posts->reject(fn (Post $p) => $top->has($p->id))->sortBy('msg_id')->values();
        $need = $max - $top->count();
        $step = $rest->count() / max(1, $need);

        $spread = collect(range(0, $need - 1))
            ->map(fn (int $i) => $rest->get((int) floor($i * $step)))
            ->filter();

        return $posts->whereIn('id', $top->keys()->merge($spread->pluck('id')))->sortBy('msg_id')->values();
    }

    /**
     * Run up to $maxBatches batches. Returns how many posts are still pending.
     * Each batch is sized for the lane it lands on.
     */
    public function run(Channel $channel, ?int $maxBatches = null): int
    {
        $maxBatches ??= (int) config('buildr.ai.extraction_batches_per_job');

        $pending = $this->pending($channel);

        for ($i = 0; $i < $maxBatches && $pending->isNotEmpty(); $i++) {
            $done = $this->label($channel, fn (AiLane $lane) => $pending->take(max(1, $lane->int('extraction_batch_size')))->values())
                ->pluck('id')
                ->flip();

            $pending = $pending->reject(fn (Post $post) => $done->has($post->id))->values();
        }

        return $pending->count();
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    public function extractBatch(Channel $channel, Collection $posts): void
    {
        $this->label($channel, fn () => $posts);
    }

    /**
     * Label one batch, chosen by $choose for the lane the call lands on.
     *
     * @param  Closure(AiLane): Collection<int, Post>  $choose
     * @return Collection<int, Post> the posts that were labelled
     */
    private function label(Channel $channel, Closure $choose): Collection
    {
        $posts = new Collection;
        $model = null;
        $rows = null;

        // Invalid output → retry once → fall back to type=other (plan §8.2).
        for ($attempt = 1; $attempt <= 2 && $rows === null; $attempt++) {
            try {
                $data = $this->runner->run('extraction', 'extract_posts', function (AiLane $lane, string $laneModel) use ($channel, $choose, &$posts, &$model) {
                    $posts = $choose($lane);
                    $model = $laneModel;

                    return [new PostExtractor($laneModel), $this->prompt($channel, $posts)];
                }, $channel);

                $model = $this->runner->lastModel() ?? $model;
                $rows = $this->validate($data, $posts);
            } catch (InvalidAiOutputException) {
                $rows = null;
            }
        }

        $model ??= (string) config('buildr.ai.extraction_model');
        $byMsgId = collect($rows ?? [])->keyBy('msg_id');

        foreach ($posts as $post) {
            $row = $byMsgId->get($post->msg_id);

            PostExtraction::updateOrCreate(['post_id' => $post->id], $row !== null ? [
                'type' => $row['type'],
                'topics' => $row['topics'],
                'projects' => $row['projects'],
                'tools' => $row['tools'],
                'milestone' => $row['milestone'],
                'tone' => $row['tone'],
                'is_personal_sensitive' => $row['is_personal_sensitive'],
                'model' => $model,
                'prompt_version' => $this->prompts->version(),
            ] : [
                'type' => PostType::Other,
                'topics' => [],
                'projects' => [],
                'tools' => [],
                'milestone' => null,
                'tone' => null,
                'is_personal_sensitive' => false,
                'model' => $model,
                'prompt_version' => $this->prompts->version(),
            ]);
        }

        return $posts;
    }

    /**
     * @param  Collection<int, Post>  $posts
     */
    private function prompt(Channel $channel, Collection $posts): string
    {
        $payload = $posts->load('links')->map(fn (Post $post) => [
            'msg_id' => $post->msg_id,
            'date' => $post->posted_at?->toDateString(),
            'lang' => $post->lang?->value,
            'text' => Str::limit((string) $post->text, AiBudgets::int('extraction_text_chars')),
            'is_forward' => $post->is_forward,
            'links' => $post->links->pluck('url')->take(5)->values()->all(),
        ])->values()->all();

        return $this->prompts->message(
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'posts',
            "Label these posts from @{$channel->username}:",
            "Label every post above from @{$channel->username}: exactly one entry per msg_id, in the same order.",
        );
    }

    /**
     * Server-side validation of the model output; anything off-schema is dropped.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function validate(array $data, Collection $posts): array
    {
        $valid = $posts->pluck('msg_id')->flip();
        $rows = [];

        foreach ((array) ($data['posts'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['msg_id']) || ! $valid->has((int) $row['msg_id'])) {
                continue;
            }

            $type = PostType::tryFrom((string) ($row['type'] ?? '')) ?? PostType::Other;
            $tone = PostTone::tryFrom((string) ($row['tone'] ?? ''));

            $projects = collect((array) ($row['projects'] ?? []))
                ->filter(fn ($p) => is_array($p) && filled($p['name'] ?? null))
                ->map(fn ($p) => [
                    'name' => Str::limit(trim((string) $p['name']), 80, ''),
                    'role' => ($p['role'] ?? '') === 'own' ? 'own' : 'mentioned',
                ])
                ->take(10)->values()->all();

            $rows[] = [
                'msg_id' => (int) $row['msg_id'],
                'type' => $type,
                'topics' => $this->strings($row['topics'] ?? [], 4),
                'projects' => $projects,
                'tools' => $this->strings($row['tools'] ?? [], 12),
                'milestone' => filled($row['milestone'] ?? null) ? Str::limit(trim((string) $row['milestone']), 120, '') : null,
                'tone' => $tone,
                'is_personal_sensitive' => (bool) ($row['is_personal_sensitive'] ?? false),
            ];
        }

        if ($rows === []) {
            throw new InvalidAiOutputException('Extraction returned no usable rows.');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values, int $max): array
    {
        return array_values(collect((array) $values)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn (string $v) => Str::limit(trim($v), 60, ''))
            ->unique(fn (string $v) => mb_strtolower($v))
            ->take($max)
            ->all());
    }
}
