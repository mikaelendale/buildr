<?php

namespace App\Services\Ai;

use App\Ai\Agents\ClaimChecker;
use App\Enums\SummaryStatus;
use App\Models\Post;
use App\Models\Summary;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Step 5 (plan §8.7).
 *
 * 1. Deterministic: cited msg_ids exist, belong to the channel and aren't
 *    deleted or sensitive; quotes are verbatim; project slugs exist;
 *    fun-fact sources exist and their numbers match.
 * 2. LLM check (separate call): every remaining claim vs. its cited posts.
 * 3. Unsupported or sensitive items are dropped, never rewritten. If hook or
 *    intro fails, the summary is marked failed_verification.
 */
class SummaryVerifier
{
    public function __construct(
        private readonly AiRunner $runner,
        private readonly AiLanes $lanes,
        private readonly Prompts $prompts,
    ) {}

    public function verify(Summary $summary): Summary
    {
        $channel = $summary->channel()->with('stats')->firstOrFail();
        $content = $summary->content ?? [];
        $citations = $summary->citations ?? [];
        $report = ['checked_at' => now()->toIso8601String(), 'dropped' => [], 'llm' => []];

        $posts = Post::where('channel_id', $channel->id)
            ->where('is_deleted', false)
            ->with('extraction')
            ->get()
            ->reject(fn (Post $p) => $p->extraction?->is_personal_sensitive === true)
            ->keyBy('msg_id');

        $stats = $channel->stats->stats ?? [];
        $projectSlugs = $channel->projects()->visible()->pluck('slug')->flip();

        // --- 1. Deterministic checks -------------------------------------------------
        $validCites = fn (array $ids) => array_values(array_filter($ids, fn ($id) => $posts->has((int) $id)));

        foreach (['hook', 'intro'] as $key) {
            $citations[$key] = $validCites($citations[$key] ?? []);
        }

        $content['building_now'] = $this->filterList($content['building_now'] ?? [], 'building_now', $report, function (array $item) use ($projectSlugs, $validCites) {
            if (! $projectSlugs->has($item['project_slug'] ?? '')) {
                return [null, 'unknown project slug'];
            }

            $item['cites'] = $validCites($item['cites'] ?? []);

            return $item['cites'] === [] ? [null, 'no valid cites'] : [$item, null];
        });

        $content['eras'] = $this->filterList($content['eras'] ?? [], 'eras', $report, function (array $item) use ($validCites) {
            $item['cites'] = $validCites($item['cites'] ?? []);

            return $item['cites'] === [] ? [null, 'no valid cites'] : [$item, null];
        });

        $content['start_here'] = $this->filterList($content['start_here'] ?? [], 'start_here', $report, function (array $item) use ($posts) {
            return $posts->has((int) ($item['msg_id'] ?? 0)) ? [$item, null] : [null, 'post missing, deleted or sensitive'];
        });

        $content['in_their_words'] = $this->filterList($content['in_their_words'] ?? [], 'in_their_words', $report, function (array $item) use ($posts) {
            $post = $posts->get((int) ($item['msg_id'] ?? 0));

            if ($post === null) {
                return [null, 'post missing, deleted or sensitive'];
            }

            if (str_word_count((string) $item['quote']) > 25 && mb_strlen((string) $item['quote']) > 200) {
                return [null, 'quote too long'];
            }

            return self::containsVerbatim((string) $post->text, (string) $item['quote'])
                ? [$item, null]
                : [null, 'quote is not verbatim'];
        });

        $content['fun_facts'] = $this->filterList($content['fun_facts'] ?? [], 'fun_facts', $report, function (array $item) use ($stats) {
            return self::funFactMatches($item, $stats) ? [$item, null] : [null, 'stat source missing or number mismatch'];
        });

        if (is_array($content['how_they_work'] ?? null)) {
            $content['how_they_work']['cites'] = $validCites($content['how_they_work']['cites'] ?? []);

            if ($content['how_they_work']['cites'] === []) {
                $report['dropped'][] = ['section' => 'how_they_work', 'item' => $content['how_they_work'], 'reason' => 'no valid cites'];
                $content['how_they_work'] = null;
            }
        }

        // --- 2. LLM check --------------------------------------------------------------
        $claims = $this->claims($content, $citations);

        if ($claims !== []) {
            $verdicts = $this->llmCheck($summary, $claims, $posts, $stats);
            $report['llm'] = $verdicts;
            $content = $this->applyVerdicts($content, $verdicts, $report);
        }

        // --- 3. Required sections ------------------------------------------------------
        $requiredOk = filled($content['hook'] ?? null) && filled($content['intro'] ?? null)
            && $citations['hook'] !== [] && $citations['intro'] !== [];

        $report['passed'] = $requiredOk;

        $summary->forceFill([
            'content' => $content,
            'citations' => array_merge($citations, $this->citationIndex($content)),
            'verification' => $report,
            'status' => $requiredOk ? SummaryStatus::Verified : SummaryStatus::FailedVerification,
        ])->save();

        return $summary;
    }

    public static function containsVerbatim(string $haystack, string $quote): bool
    {
        // Models swap in typographic quotes, Unicode hyphens/dashes and
        // non-breaking spaces; none of those change the words.
        $normalize = fn (string $s) => trim((string) preg_replace(
            ['/[\x{2010}-\x{2015}\x{2212}]/u', '/[\s\x{00A0}\x{202F}]+/u'],
            ['-', ' '],
            str_replace(
                ['“', '”', '‘', '’', '«', '»', '…'],
                ['"', '"', "'", "'", '"', '"', '...'],
                $s,
            ),
        ));

        $quote = trim($normalize($quote), " \"'");

        if ($quote === '') {
            return false;
        }

        if (str_contains($normalize($haystack), $quote)) {
            return true;
        }

        // Same words in the same order, ignoring punctuation and emoji
        // ("less —\nless information 📣" vs "less, less information").
        $words = function (string $s): string {
            preg_match_all('/[\p{L}\p{M}\p{N}]+/u', mb_strtolower($s), $m);

            return ' '.implode(' ', $m[0]).' ';
        };
        $needle = $words($quote);

        return trim($needle) !== '' && str_contains($words($haystack), $needle);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $stats
     */
    public static function funFactMatches(array $item, array $stats): bool
    {
        $source = (string) ($item['source'] ?? '');

        if (! str_starts_with($source, 'stats.')) {
            return false;
        }

        $value = data_get($stats, substr($source, 6));

        if ($value === null || is_array($value)) {
            return false;
        }

        $text = (string) ($item['text'] ?? '');

        if (! is_numeric($value)) {
            return str_contains(mb_strtolower($text), mb_strtolower((string) $value));
        }

        preg_match_all('/\d+(?:[.,]\d+)?/', str_replace(',', '', $text), $m);

        if ($m[0] === []) {
            return true;
        }

        return collect($m[0])->contains(fn ($n) => abs((float) $n - (float) $value) < 0.051);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $report
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>|null, 1: string|null}  $check
     * @return list<array<string, mixed>>
     */
    private function filterList(array $items, string $section, array &$report, callable $check): array
    {
        $kept = [];

        foreach ($items as $item) {
            [$result, $reason] = $check($item);

            if ($result === null) {
                $report['dropped'][] = ['section' => $section, 'item' => $item, 'reason' => $reason];

                continue;
            }

            $kept[] = $result;
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, list<int>>  $citations
     * @return list<array{id: string, text: string, cites: list<int>}>
     */
    private function claims(array $content, array $citations): array
    {
        $claims = [];

        if (filled($content['hook'] ?? null)) {
            $claims[] = ['id' => 'hook', 'text' => $content['hook'], 'cites' => $citations['hook'] ?? []];
        }

        if (filled($content['intro'] ?? null)) {
            $claims[] = ['id' => 'intro', 'text' => $content['intro'], 'cites' => $citations['intro'] ?? []];
        }

        if (($content['vibe_tags'] ?? []) !== []) {
            $claims[] = ['id' => 'vibe_tags', 'text' => 'Vibe tags: '.implode(', ', $content['vibe_tags']), 'cites' => $citations['intro'] ?? []];
        }

        foreach ($content['building_now'] ?? [] as $i => $item) {
            $claims[] = ['id' => "building_now.{$i}", 'text' => $item['project_slug'].': '.$item['line'], 'cites' => $item['cites']];
        }

        foreach ($content['eras'] ?? [] as $i => $item) {
            $claims[] = ['id' => "eras.{$i}", 'text' => "{$item['name']} ({$item['from']} to {$item['to']}): {$item['story']}", 'cites' => $item['cites']];
        }

        foreach ($content['start_here'] ?? [] as $i => $item) {
            $claims[] = ['id' => "start_here.{$i}", 'text' => $item['why'], 'cites' => [$item['msg_id']]];
        }

        foreach ($content['in_their_words'] ?? [] as $i => $item) {
            $claims[] = ['id' => "in_their_words.{$i}", 'text' => $item['quote'], 'cites' => [$item['msg_id']]];
        }

        if (is_array($content['how_they_work'] ?? null)) {
            $claims[] = ['id' => 'how_they_work', 'text' => $content['how_they_work']['text'], 'cites' => $content['how_they_work']['cites']];
        }

        foreach ($content['fun_facts'] ?? [] as $i => $item) {
            $claims[] = ['id' => "fun_facts.{$i}", 'text' => $item['text'].' (computed from channel statistics)', 'cites' => []];
        }

        return $claims;
    }

    /**
     * @param  list<array{id: string, text: string, cites: list<int>}>  $claims
     * @param  Collection<int, Post>  $posts
     * @param  array<string, mixed>  $stats
     * @return array<string, array{verdict: string, reason: ?string}>
     */
    private function llmCheck(Summary $summary, array $claims, Collection $posts, array $stats = []): array
    {
        // Claims are split for the lane that will check them; if it runs out
        // mid-way another lane takes over (the job retries when none fits).
        $lane = $this->lanes->pick('verify', advance: false) ?? $this->lanes->all()[0];
        $limit = $lane->int('verify_post_chars');
        $text = fn (int $id) => Str::limit((string) $posts->get($id)?->text, $limit);
        $verdicts = [];

        // The writer is asked to use real numbers from the stats, so the
        // checker sees them too (computed by us, never by a model).
        $facts = collect($stats)->except(SummaryGenerator::NOT_FOR_AI)->filter(fn ($v) => $v !== null)->all();

        foreach ($this->chunkClaims($claims, $text, $lane->int('verify_chars_per_call')) as $chunk) {
            // Dates ride along so era month ranges can be checked.
            $cited = collect($chunk)->pluck('cites')->flatten()->unique()
                ->mapWithKeys(fn ($id) => [(string) $id => [
                    'date' => $posts->get((int) $id)?->posted_at?->toDateString(),
                    'text' => $text((int) $id),
                ]])
                ->all();

            $data = $this->runner->run('verify', 'verify_summary', fn (AiLane $lane, string $model) => [
                new ClaimChecker($model, $summary->mode),
                $this->prompts->message(
                    json_encode(['stats' => $facts, 'posts' => $cited, 'claims' => $chunk], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    'evidence',
                    '',
                    'Check every claim above against its cited posts and the stats. Return exactly one verdict per claim id.',
                ),
            ], $summary, prefer: $lane);

            foreach ((array) ($data['verdicts'] ?? []) as $row) {
                $id = (string) ($row['id'] ?? '');

                if ($id !== '') {
                    $verdicts[$id] = [
                        'verdict' => in_array($row['verdict'] ?? '', ['supported', 'unsupported', 'sensitive'], true) ? $row['verdict'] : 'unsupported',
                        'reason' => $row['reason'] ?? null,
                    ];
                }
            }
        }

        // A claim the checker skipped is treated as unsupported.
        foreach ($claims as $claim) {
            $verdicts[$claim['id']] ??= ['verdict' => 'unsupported', 'reason' => 'not checked'];
        }

        return $verdicts;
    }

    /**
     * Split claims so each checker call carries at most `verify_chars_per_call`
     * characters of cited post text (small per-minute token budgets).
     *
     * @param  list<array{id: string, text: string, cites: list<int>}>  $claims
     * @param  callable(int): string  $text
     * @return list<list<array{id: string, text: string, cites: list<int>}>>
     */
    private function chunkClaims(array $claims, callable $text, int $budget): array
    {
        $chunks = [];
        $current = [];
        $seen = [];
        $chars = 0;

        foreach ($claims as $claim) {
            $new = array_diff(array_map('intval', $claim['cites']), array_keys($seen));
            $extra = array_sum(array_map(fn (int $id) => mb_strlen($text($id)), $new)) + mb_strlen($claim['text']);

            if ($budget > 0 && $current !== [] && $chars + $extra > $budget) {
                $chunks[] = $current;
                $current = [];
                $seen = [];
                $chars = 0;
                $new = array_map('intval', $claim['cites']);
                $extra = array_sum(array_map(fn (int $id) => mb_strlen($text($id)), $new)) + mb_strlen($claim['text']);
            }

            $current[] = $claim;
            $seen += array_fill_keys($new, true);
            $chars += $extra;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, array{verdict: string, reason: ?string}>  $verdicts
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function applyVerdicts(array $content, array $verdicts, array &$report): array
    {
        // Fun facts are numbers already matched against channel_stats above, so
        // the checker only screens them for sensitive topics.
        $failed = fn (string $id) => str_starts_with($id, 'fun_facts.')
            ? ($verdicts[$id]['verdict'] ?? 'supported') === 'sensitive'
            : ($verdicts[$id]['verdict'] ?? 'supported') !== 'supported';

        foreach (['hook', 'intro', 'how_they_work'] as $key) {
            if (isset($verdicts[$key]) && $failed($key)) {
                $report['dropped'][] = ['section' => $key, 'item' => $content[$key], 'reason' => $verdicts[$key]['verdict'].': '.$verdicts[$key]['reason']];
                $content[$key] = $key === 'how_they_work' ? null : '';
            }
        }

        if (isset($verdicts['vibe_tags']) && $failed('vibe_tags')) {
            $report['dropped'][] = ['section' => 'vibe_tags', 'item' => $content['vibe_tags'], 'reason' => $verdicts['vibe_tags']['reason']];
            $content['vibe_tags'] = [];
        }

        foreach (['building_now', 'eras', 'start_here', 'in_their_words', 'fun_facts'] as $section) {
            $kept = [];

            foreach ($content[$section] ?? [] as $i => $item) {
                $id = "{$section}.{$i}";

                if ($failed($id)) {
                    $report['dropped'][] = ['section' => $section, 'item' => $item, 'reason' => $verdicts[$id]['verdict'].': '.$verdicts[$id]['reason']];

                    continue;
                }

                $kept[] = $item;
            }

            $content[$section] = $kept;
        }

        return $content;
    }

    /**
     * Flat claim → msg_ids map for the admin view.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, list<int>>
     */
    private function citationIndex(array $content): array
    {
        $index = [];

        foreach (['building_now', 'eras'] as $section) {
            foreach ($content[$section] ?? [] as $i => $item) {
                $index["{$section}.{$i}"] = $item['cites'] ?? [];
            }
        }

        foreach (['start_here', 'in_their_words'] as $section) {
            foreach ($content[$section] ?? [] as $i => $item) {
                $index["{$section}.{$i}"] = [(int) $item['msg_id']];
            }
        }

        if (is_array($content['how_they_work'] ?? null)) {
            $index['how_they_work'] = $content['how_they_work']['cites'] ?? [];
        }

        return $index;
    }
}
